<?php

/**
 * Copyright (C) 2019 Petr Hucik <petr@getdatakick.com>
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License (AFL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/afl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@getdatakick.com so we can send you a copy immediately.
 *
 * @author    Petr Hucik <petr@getdatakick.com>
 * @copyright 2017-2019 Petr Hucik
 * @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
 */

require_once __DIR__ . '/../../classes/AvardaPaymentsModuleFrontController.php';

use AvardaPayments\AvardaException;
use AvardaPayments\Utils;
use PrestaShop\PrestaShop\Adapter\Cart\CartPresenter;
use PrestaShop\PrestaShop\Core\Crypto\Hashing;

class AvardaPaymentsCheckoutModuleFrontController extends AvardaPaymentsModuleFrontController
{
    // dummy variables
    public $dummyEmail = 'Eg8ZCADurkDbcEz@UL2WbP9oGuQfEGP.fi';
    public $dummyPassword = 'mP4QVkHDNwBCDLLNHuQrsG4g';
    public $dummyFirst = 'hXxZCbsHhdqgnhaYBCVh';
    public $dummyLast = 'nmzDbtFujHNYJisvmFUC';
    public $dummyAlias = 'dszjnzcmzcwbfkxiqwatzsko';
    public $dummyAddress = 'byjZaijqJQfaKNtsdELjkMHo 28';
    public $dummyPostcode = 98989;
    public $dummyCity = 'Yebcjvnongspktxtruomg';
    public $dummyCountry = 7;

    /**
     * @throws Exception
     */
    public function initContent()
    {
        parent::initContent();

        global $cookie;

        $settings = $this->module->getSettings();
        $mode = $settings->getMode();
        $redirectUrlParams = [];

        if (static::validCart($cart = $this->context->cart)) {
            if (!$cart->id_customer) {
                // cart has no customer id, so user is not logged in
                // create dummy guest user, where we can populate user data afterwards
                $customerID = $this->createDummyGuestCustomer($cart);
            }

            $bindings = $settings->getGlobalPaymentBindings();
            $globalPayment = isset($bindings[$this->context->language->id]) &&
                $bindings[$this->context->language->id];

            $session = AvardaSession::getForCart($cart, $mode, $globalPayment);
            $session->error_message = '';

            $errorFromRequest = $this->processRequest($session, $cart);
            if (!empty($errorFromRequest)) {
                $this->setError($this->module->l('An error occurred with Avarda service'));
                $session->error_message = $errorFromRequest->__toString();
                $session->save();
            }

            $redirectUrlParams['id_session'] = $session->id;
        } else {
            $this->setError($this->module->l('Empty cart'));
        }

        $this->registerStylesheet('avarda-css', 'modules/avardapayments/views/css/avarda.css');

        $this->context->smarty->assign([
            'avardaPurchaseToken' => isset($session) ? $session->purchase_token : '',
            'avardaPurchaseId' => isset($session) ? $session->purchase_id : '',
            'avardaCheckoutUrl' => $this->context->link->getModuleLink($this->module->name, 'checkout'),
            // 'avardaRedirectUrl' => $this->context->link->getModuleLink($this->module->name, 'return', $redirectUrlParams, true),
            'avardaRedirectUrl' => $this->context->link->getModuleLink($this->module->name, 'checkout'),
            'paymentCallbackUrl' => $this->context->link->getModuleLink($this->module->name, 'notification'),
            'customerInfo' => '',
            'apiErrorMsg' => isset($session) ? $session->error_message : '',
            'showCart' => $settings->showCart(),
            'formLanguage' => isset($session) ? $this->getApi($session->global)->getLanguageById($cookie->id_lang) : null,
            'apiEnv' => isset($session) ? $this->getApi($session->global)->getApiEnv() : null,
        ]);

        $this->setTemplate('module:avardapayments/views/templates/front/checkout.tpl');
    }

    /**
     * @param AvardaSession $session
     * @param Cart $cart
     *
     * @throws AvardaException
     * @throws Exception
     */
    public function processRequest(AvardaSession $session, Cart $cart)
    {
        $error = '';
        global $cookie;
        try {
            // get cart info
            $deliveryDescription = $this->module->l('Delivery cost (Including taxes)');
            $cartInfo = Utils::getCartInfo($cart, $deliveryDescription);
            $cartSignature = Utils::getCartInfoSignature($cartInfo);
            // get customer info
            $customerInfo = Utils::getCustomerInfo($cart);

            $utcTimeNow = new \DateTime('now', new \DateTimeZone('UTC'));
            $utcTimeMinus30 = $utcTimeNow->modify('-30 minutes');
            $utcTimeNowString = $utcTimeMinus30->format('Y-m-d H:i:s');

            // initialize sessions if necesseary
            if (empty($session->purchase_id)) {
                $info = json_decode($session->info);
                // if we are using ghost account, do not create purchase id
                if (($customerInfo && $customerInfo['Mail'] != $this->dummyEmail) || ($info && isset($info->Mail))) {
                    if ($customerInfo['Mail'] == $this->dummyEmail) {
                        $customerInfo['Mail'] = $info->Mail;
                    }
                    // filter address data
                    $customerInfo = $this->filterDummyAddressData($customerInfo);
                    // add filtered customer data into info
                    $info = array_merge($cartInfo, $customerInfo);
                    $dataArr = $this->getApi($session->global)->initializePayment($info);
                    $data = json_decode(json_encode($dataArr), false);

                    $purchaseToken = $data->jwt;
                    $purchaseId = $data->purchaseId;
                    $expireTimestamp = $data->expiredUtc;

                    $session->purchase_id = $purchaseId;
                    $session->purchase_token = $purchaseToken;
                    $session->cart_signature = $cartSignature;

                    $session->purchase_expire_timestamp = $expireTimestamp;
                    $session->status = 'processing';
                } else {
                    $session->purchase_id = '';
                    $session->purchase_token = '';
                    $session->purchase_expire_timestamp = '';
                }

                $session->save();

            // Purchase is expired if less than (now - 30) minutes
            } elseif (!empty($session->purchase_id) && $session->purchase_expire_timestamp < $utcTimeNowString) {
                $this->updatePurchaseSession($session);
            }

            // update session items if cart signature changes
            if ($session->cart_signature !== $cartSignature && !empty($session->purchase_id)) {
                $itemsArray = [
                    'items' => $cartInfo['items'],
                ];
                $this->getApi($session->global)->updateItems($session->purchase_id, $itemsArray);
                $session->cart_signature = $cartSignature;

                //If user removes products during checkout set purchase id to blank
                if (empty($session->purchase_id)) {
                    $session->purchase_id = '';
                    $session->purchase_token = '';
                    $session->purchase_expire_timestamp = '';
                }
                $session->save();
            }

            // process ajax calls
            if (Tools::getValue('ajax') && Tools::getValue('action')) {
                exit($this->processAjax($session, Tools::getValue('action'), Tools::getAllValues()));
            }

            // render checkout page
            $this->registerJavascript('avarda-client', $this->getJavascriptUrl(), ['server' => 'remote', 'position' => 'head']);

            $this->context->smarty->assign([
                'formLanguage' => $this->getApi($session->global)->getLanguageById($cookie->id_lang),
                'avardaPurchaseToken' => $session->purchase_token,
                'avardaPurchaseId' => $session->purchase_id,
                'avardaCheckoutUrl' => $this->context->link->getModuleLink($this->module->name, 'checkout'),
                'customerInfo' => $customerInfo,
            ]);
            $this->renderCart();
            $this->setTemplate('module:avardapayments/views/templates/front/checkout.tpl');
        } catch (Exception $e) {
            Logger::addLog('processRequest(): error - ' . $e, 3, null, null, null, true);
            $error = $e;
        }

        return $error;
    }

    /**
     * @param AvardaSession $session
     */
    public function updatePurchaseSession($session)
    {
        $newJwtArr = $this->getApi($session->global)->execute('/api/partner/payments/' . $session->purchase_id . '/token', $session->purchase_id, 'GET', 'reclaimPurchaseToken()');

        $data = json_decode(json_encode($newJwtArr), false);
        $session->purchase_token = $data->jwt;

        $utcTimeStampNow = new \DateTime('now', new \DateTimeZone('UTC'));
        $utcTimePlus30 = $utcTimeStampNow->modify('+30 minutes');
        $formattedNewTimestamp = $utcTimePlus30->format('Y-m-d H:i:s');
        $session->purchase_expire_timestamp = $formattedNewTimestamp;
        $session->save();
    }

    /**
     * @return bool
     */
    protected function checkIfPaymentOptionIsAvailable()
    {
        foreach (Module::getPaymentModules() as $module) {
            if (($module['name'] === $this->module->name) && $this->module->active) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array $data
     */
    protected function displayAjaxResponse(array $data)
    {
        die(json_encode($data));
    }

    /**
     * @param string $message
     */
    protected function displayAjaxError($message)
    {
        $this->displayAjaxResponse(['error' => $message]);
    }

    protected function validateRequestData()
    {
        if ((int)Tools::getValue('createUser') &&
            !Validate::isPlaintextPassword(Tools::getValue('pswd'))) {
            $this->displayAjaxError($this->module->l('Invalid password.', 'checkout'));
        }
    }

    /**
     * @param AvardaSession $session
     * @param stdClass $payment
     *
     * @return bool
     */
    protected function updateSessionData(AvardaSession $session, stdClass $payment)
    {
        $session->create_customer = (int)Tools::getValue('createUser');
        if ($session->create_customer) {
            $session->passwd = (new Hashing())->hash(Tools::getValue('pswd'));
        }

        $session->newsletter = (int)Tools::getValue('optForNews');
        $session->id_carrier = (int)Tools::getValue('id_carrier');
        $session->order_message = trim((string)Tools::getValue('order_message'));

        $session->is_gift = (int)Tools::getValue('isGift');
        if ($session->is_gift) {
            $session->gift_message = trim((string)Tools::getValue('giftMsg'));
        }

        $session->is_recycled = (int)Tools::getValue('isRecycled');
        $session->info = json_encode($payment);

        return $session->save();
    }

    /**
     * @param stdClass $payment
     * @param Customer $customer
     */
    protected function updateCustomerNameAndEmail(stdClass $payment, Customer $customer)
    {
        if (($customer->firstname != $this->dummyFirst) &&
            ($customer->lastname != $this->dummyLast)) {
            return;
        }

        $firstname = '-';
        $lastname = '-';

        if ($payment->mode === 'B2C') {
            $mode = $payment->b2C;
            $firstname = $mode->invoicingAddress->firstName;
            $lastname = $mode->invoicingAddress->lastName;
        } else {
            $mode = $payment->b2B;

            if (isset($mode->customerInfo->firstName) && $mode->customerInfo->firstName) {
                $firstname = $mode->customerInfo->firstName;
            }

            if (isset($mode->customerInfo->lastName) && $mode->customerInfo->lastName) {
                $lastname = $mode->customerInfo->lastName;
            }
        }

        if (($firstname === '-') || ($lastname === '-')) {
            $customer->firstname = '-';
            $customer->lastname = '-';
        } else {
            $customer->firstname = Utils::truncateValue($firstname, 32, true);
            $customer->lastname = Utils::truncateValue($lastname, 32, true);
        }

        $customer->email = $mode->userInputs->email;
    }

    /**
     * @param AvardaSession $session
     *
     * @return bool
     */
    protected function removePasswordFromSession(AvardaSession $session)
    {
        $session->passwd = '';

        return $session->save();
    }

    /**
     * @param AvardaSession $session
     * @param int $customerId
     *
     * @return bool
     */
    protected function updateSessionCustomerId(AvardaSession $session, $customerId)
    {
        $session->id_customer = (int)$customerId;

        return $session->save();
    }

    /**
     * @param AvardaSession $session
     * @param stdClass $payment
     *
     * @return bool
     */
    protected function updateCustomerData(AvardaSession $session, stdClass $payment)
    {
        $customer = new Customer((int)$this->context->cart->id_customer);
        if (!Validate::isLoadedObject($customer)) {
            return false;
        }

        $this->updateCustomerNameAndEmail($payment, $customer);

        if ($customer->is_guest && $session->create_customer) {
            $customer->is_guest = 0;
            $customer->passwd = $session->passwd;

            $this->removePasswordFromSession($session);
        }

        if (!$customer->newsletter && $session->newsletter) {
            $customer->newsletter = 1;
        }

        if (!$customer->save()) {
            return false;
        }

        $this->updateCustomerContext($customer, true);
        $this->updateSessionCustomerId($session, $customer->id);

        return true;
    }

    /**
     * @param int $cartId
     * @param int $addressId
     * @param int $newAddressId
     *
     * @return bool
     */
    protected function updateCartProductAddress($cartId, $addressId, $newAddressId)
    {
        return Db::getInstance()->execute('
            UPDATE `' . _DB_PREFIX_ . 'cart_product`
            SET `id_address_delivery` = ' . (int)$newAddressId . '
            WHERE  `id_cart` = ' . (int)$cartId . ' AND `id_address_delivery` = ' . (int)$addressId . '
        ');
    }

    /**
     * @param int $cartId
     * @param int $addressId
     * @param int $newAddressId
     *
     * @return bool
     */
    protected function updateCustomizationAddress($cartId, $addressId, $newAddressId)
    {
        return Db::getInstance()->execute('
            UPDATE `' . _DB_PREFIX_ . 'customization`
            SET `id_address_delivery` = ' . (int)$newAddressId . '
            WHERE  `id_cart` = ' . (int)$cartId . ' AND `id_address_delivery` = ' . (int)$addressId . '
        ');
    }

    /**
     * @param stdClass $payment
     *
     * @return bool
     */
    protected function updateAddressData(stdClass $payment)
    {
        $mode = $this->getPaymentModeInfo($payment);
        $checkoutSite = $payment->checkoutSite;
        $invoiceAddressInfo = Utils::extractAddressInfo('invoicingAddress', $checkoutSite, $mode, $payment->mode);
        if (!Utils::validAddressInfo($invoiceAddressInfo)) {
            return false;
        }

        $cart = $this->context->cart;
        $customer = $this->context->customer;

        $cart->id_address_invoice = (int)static::resolveAddress(
            $customer,
            $cart->id_address_invoice,
            $invoiceAddressInfo
        );

        $deliveryAddressInfo = Utils::extractAddressInfo('deliveryAddress', $checkoutSite, $mode, $payment->mode);
        if (!Utils::validAddressInfo($deliveryAddressInfo)) {
            $deliveryAddressInfo = $invoiceAddressInfo;
        }

        $deliveryAddressId = (int)static::resolveAddress($customer, $cart->id_address_delivery, $deliveryAddressInfo);
        if ($deliveryAddressId !== (int)$cart->id_address_delivery) {
            if (!$this->updateCartProductAddress($cart->id, $cart->id_address_delivery, $deliveryAddressId) ||
                !$this->updateCustomizationAddress($cart->id, $cart->id_address_delivery, $deliveryAddressId)) {
                return false;
            }

            $cart->id_address_delivery = $deliveryAddressId;
            $cart->delivery_option = json_encode([$cart->id_address_delivery => $cart->id_carrier . ',']);
        }

        return $cart->save();
    }

    /**
     * @param AvardaSession $session
     *
     * @return bool
     */
    protected function updateCartData(AvardaSession $session)
    {
        $cart = $this->context->cart;

        if (Configuration::get('PS_RECYCLABLE_PACK')) {
            $cart->recyclable = (int)$session->is_recycled;
        }

        if (Configuration::get('PS_GIFT_WRAPPING')) {
            $cart->gift = (int)$session->is_gift;
            $cart->gift_message = (string)$session->gift_message;
        }

        return $cart->save();
    }

    /**
     * @param AvardaSession $session
     * @param stdClass $payment
     */
    protected function updateContext(AvardaSession $session, stdClass $payment)
    {
        if (!$this->checkIfPaymentOptionIsAvailable()) {
            $this->displayAjaxError($this->module->l('This payment method is not available.', 'checkout'));
        }

        if ($this->getSettings()->getUseOnePage()) {
            $this->validateRequestData();

            if (!$this->updateSessionData($session, $payment)) {
                $this->displayAjaxError($this->module->l('Failed to update session.', 'checkout'));
            }

            if (!$this->updateCustomerData($session, $payment)) {
                $this->displayAjaxError($this->module->l('Failed to update customer.', 'checkout'));
            }
        }

        if (!$this->updateAddressData($payment)) {
            $this->displayAjaxError($this->module->l('Failed to update addresses.', 'checkout'));
        }

        if ($this->getSettings()->getUseOnePage() && !$this->updateCartData($session)) {
            $this->displayAjaxError($this->module->l('Failed to update cart.', 'checkout'));
        }

        $this->displayAjaxResponse(['success' => 1]);
    }

    /**
     * @param AvardaSession $session
     * @param stdClass $payment
     */
    protected function correctCountryInCartAddresses(AvardaSession $session, stdClass $payment)
    {
        $mode = $this->getPaymentModeInfo($payment);
        $invoiceAddressInfo = Utils::extractAddressInfo('invoicingAddress', null, $mode, $payment->mode);
        if (!Utils::validAddressInfo($invoiceAddressInfo)) {
            return false;
        }

        $cart = new Cart((int)$session->id_cart);

        $invoiceAddress = new Address((int)$cart->id_address_invoice);
        $invoiceAddress->id_country = $invoiceAddressInfo['id_country'];

        if (!$invoiceAddress->save()) {
            return false;
        }

        $deliveryAddressInfo = Utils::extractAddressInfo('deliveryAddress', null, $mode, $payment->mode);
        if (!Utils::validAddressInfo($deliveryAddressInfo)) {
            $deliveryAddressInfo = $invoiceAddressInfo;
        }

        if ((int)$cart->id_address_delivery !== (int)$cart->id_address_invoice) {
            $deliveryAddress = new Address((int)$cart->id_address_delivery);
            $deliveryAddress->id_country = $deliveryAddressInfo['id_country'];

            if (!$deliveryAddress->save()) {
                return false;
            }
        } else {
            $deliveryAddressId = (int)static::resolveAddress(
                new Customer((int)$cart->id_customer),
                $cart->id_address_delivery,
                $deliveryAddressInfo
            );
    
            if ($deliveryAddressId !== (int)$cart->id_address_delivery) {
                if (!$this->updateCartProductAddress($cart->id, $cart->id_address_delivery, $deliveryAddressId) ||
                    !$this->updateCustomizationAddress($cart->id, $cart->id_address_delivery, $deliveryAddressId)) {
                    return false;
                }
    
                $cart->id_address_delivery = $deliveryAddressId;
                $cart->delivery_option = json_encode([$cart->id_address_delivery => $cart->id_carrier . ',']);
    
                if (!$cart->save()) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @param AvardaSession $session
     * @param stdClass $payment
     */
    protected function createOrder(AvardaSession $session, stdClass $payment)
    {
        if (($this->getPaymentStepName($payment) !== 'Completed') ||
            !$this->validateOrder($session, $payment) ||
            !Validate::isLoadedObject($order = new Order((int)$this->module->currentOrder))) {
            $this->displayAjaxError($this->module->l('Failed to create order.', 'checkout'));
        }

        $this->updateOrderState($session, $payment, $order);

        if (!$this->correctCountryInCartAddresses($session, $payment)) {
            $this->displayAjaxError($this->module->l('Failed to correct country in cart addresses.', 'checkout'));
        }

        $this->displayAjaxResponse(['url' => $this->getOrderConfirmationUrl($order, $payment)]);
    }

    /**
     * @param AvardaSession $session
     * @param string $action
     *
     * @return mixed
     *
     * @throws AvardaException
     * @throws PrestaShopException
     */
    protected function processAjax($session, $action, $params)
    {
        if ($action === 'update_context') {
            $this->updateContext($session, $this->getPaymentInfo($session));
        }

        if ($action === 'create_order') {
            $this->createOrder($session, $this->getPaymentInfo($session));
        }

        if ($action === 'updatecarrier') {
            return $this->updateCarrier($session, $params);
        }

        if ($action === 'updateCart') {
            $cart = new Cart($session->id_cart);
            $deliveryDescription = $this->module->l('Delivery cost (Including taxes)');

            $cartInfo = Utils::getCartInfo($cart, $deliveryDescription);
            $itemsArray = [
                'items' => $cartInfo['items'],
            ];

            return $this->getApi($session->global)->updateItems($session->purchase_id, $itemsArray);
        }

        if ($action === 'updatewrapping') {
            return $this->updateWrapping($session, $params);
        }

        if ($action === 'refresh') {
            return $session->purchase_id;
        }
        // if ($action === 'validate') {
        //     try {
        //         return $this->validatePayment($session);
        //     } catch (Exception $e) {
        //         Logger::addLog('processAjax(): Validate error: ' . $e, 1, null, null, null, true);

        //         return 'validateFailed';
        //     }
        // }

        if ($action === 'adduserdata') {
            return $this->updateCustomerInfo($session, $params);
        }
        // if ($action === 'createuser') {
        //     try {
        //         $created = $this->createCustomer($session, $params);
        //         if ($created) {
        //             return $this->validatePayment($session);
        //         }
        //     } catch (Exception $e) {
        //         if (strpos($e, 'Notice: Undefined offset:') || strpos($e, 'Notice: Undefined variable: order')) {
        //             //do nothing here, go to confirmation page
        //         } else {
        //             Logger::addLog('processAjax(): createcustomer / validatepayment error: ' . $e, 1, null, null, null, true);
        //         }
        //     }
        // }
        // if ($action === 'compareuser') {
        //     $this->compareCustomer($session);

        //     return true;
        // }
        // if ($action === 'addusersettings') {
        //     return $this->addSessionSettings($session, $params);
        // }

        return false;
    }

    // protected function addSessionSettings($session, $params)
    // {
    //     // init return value indicators
    //     $hasCarrier = true;
    //     $validPassword = true;

    //     if ($params['createUser'] == 'true') {
    //         $session->create_customer = 1;
    //         if ($params['pswd']) {
    //             // check if password is valid
    //             if (Validate::isPlaintextPassword($params['pswd'])) {
    //                 // password is valid, hash it
    //                 $crypto = new Hashing();
    //                 $session->passwd = $crypto->hash($params['pswd']);
    //                 if (!Validate::isHashedPassword($session->passwd)) {
    //                     // something went wrong with hashing - do not accept the password
    //                     $validPassword = false;
    //                 }
    //             } else {
    //                 $validPassword = false;
    //             }
    //         }
    //     } else {
    //         $session->create_customer = 0;
    //     }

    //     if ($params['optForNews'] == 'true') {
    //         $session->newsletter = 1;
    //     } else {
    //         $session->newsletter = 0;
    //     }
    //     $cart = new Cart($session->id_cart);
    //     $carriers = $this->getShippingOptionIDs($cart);
    //     if ($carriers) {
    //         $carrier = htmlspecialchars($params['idCarrier']);
    //         if (in_array($carrier, $carriers)) {
    //             $session->id_carrier = $params['idCarrier'];
    //         } else {
    //             $hasCarrier = false;
    //         }
    //     } else {
    //         $hasCarrier = false;
    //     }

    //     if ($params['orderMsg']) {
    //         $order_message = trim(htmlspecialchars(strip_tags($params['orderMsg'])));
    //         $session->order_message = $params['orderMsg'];
    //     }

    //     if ((int) Configuration::get('PS_GIFT_WRAPPING')) {
    //         // shop supports gifting
    //         // check if order is gift
    //         if ($params['isGift'] == 'true') {
    //             $session->is_gift = 1;
    //             // add gift message if it has one
    //             if ($params['giftMsg']) {
    //                 $gift_message = trim(htmlspecialchars(strip_tags($params['giftMsg'])));
    //                 $session->gift_message = $gift_message;
    //             }
    //         } else {
    //             $session->is_gift = 0;
    //         }
    //     }

    //     if ((int) Configuration::get('PS_RECYCLABLE_PACK')) {
    //         // shop supports recycled packaging
    //         // check if recycling is checked
    //         if ($params['isRecycled'] == 'true') {
    //             $session->is_recycled = 1;
    //         } else {
    //             $session->is_recycled = 0;
    //         }
    //     }

    //     $session->save();

    //     if (!$hasCarrier) {
    //         return 'noCarrier';
    //     } elseif (!$validPassword) {
    //         return 'invalidPassword';
    //     } else {
    //         return true;
    //     }
    // }

    protected function filterDummyCustomerData(Customer $customer)
    {
        // filter dummy customer data
        if ($customer->firstname == $this->dummyFirst) {
            $customer->firstname = '';
        }
        if ($customer->lastname == $this->dummyLast) {
            $customer->lastname = '';
        }
        if ($customer->firstname == $this->dummyFirst) {
            $customer->firstname = '';
        }
        $address = $customer->addresses[0];

        return $customer;
    }

    protected function filterDummyAddressData($customerInfo)
    {
        try {
            if (!empty($customerInfo)) {
                $prefixes = ['Delivery', 'Invoicing'];
                foreach ($prefixes as $prefix) {
                    // filter address data by clearing it if it matches dummy data
                    if ($customerInfo[$prefix . 'FirstName'] == $this->dummyFirst) {
                        $customerInfo[$prefix . 'FirstName'] = '';
                    }
                    if ($customerInfo[$prefix . 'LastName'] == $this->dummyLast) {
                        $customerInfo[$prefix . 'LastName'] = '';
                    }
                    if ($customerInfo[$prefix . 'AddressLine1'] == $this->dummyAddress) {
                        $customerInfo[$prefix . 'AddressLine1'] = '';
                    }
                    if ($customerInfo[$prefix . 'Zip'] == $this->dummyPostcode) {
                        $customerInfo[$prefix . 'Zip'] = '';
                    }
                    if ($customerInfo[$prefix . 'City'] == $this->dummyCity) {
                        $customerInfo[$prefix . 'City'] = '';
                    }
                }
            }
        } catch (Exception $e) {
            Logger::addLog('filterDummyAddressData(): error - ' . $e, 3, null, null, null, true);
        }
        // Do we have to return empty customerInfo here?
        // return filtered address
        return $customerInfo;
    }

    /**
     * update customer info into context
     *
     * @param Customer customer which is added to context
     * @param login whether to log in updated customer
     */
    protected function updateCustomerContext(Customer $customer, $login = false)
    {
        $context = Context::getContext();
        $context->customer = $customer;
        $context->cookie->id_customer = (int) $customer->id;
        $context->cookie->customer_lastname = $customer->lastname;
        $context->cookie->customer_firstname = $customer->firstname;
        $context->cookie->passwd = $customer->passwd;
        if ($login) {
            $context->cookie->logged = 1;
            $customer->logged = 1;
        } else {
            $context->cookie->logged = 0;
            $customer->logged = 0;
            if ($customer->email != $this->dummyEmail) {
                $context->customer->logout();
            }
        }
        $context->cookie->email = $customer->email;
        $context->cookie->is_guest = $customer->isGuest();

        $context->cart->secure_key = $customer->secure_key;

        $context->cart->id_customer = (int) $customer->id;

        $context->cart->save();
        $context->cookie->id_cart = (int) $context->cart->id;
        $context->cookie->write();
        $context->cart->autosetProductAddress();
    }

    protected function updateCustomerInfo($session, $params)
    {
        // init return value
        $updated = false;
        // init result checkers
        $updatedCustomer = false;
        $updatedAddress = false;

        // get cart from context
        $cart = $this->context->cart;

        // get customer from cart
        $customer_id = $cart->id_customer;

        if ($customer_id) {
            $info = json_decode($session->info);
            $info['Mail'] = $params['email'];
            $session->info = json_encode($info);
            $session->save();
            $updatedCustomer = true;
            // update ghost customer with given info
            // get customer address
            $address_id = Address::getFirstCustomerAddressId($customer_id);
            if ($address_id) {
                // update postcode to address
                $address = new Address($address_id);
                $address->postcode = $params['zip'];
                if ($address->update()) {
                    $updatedAddress = true;
                } else {
                    Logger::addLog('updateCustomerInfo(): address update failed', 3, null, null, null, true);
                }
            } else {
                Logger::addLog('updateCustomerInfo(): address id not found', 3, null, null, null, true);
            }
        } else {
            Logger::addLog('updateCustomerInfo(): customer id not found', 3, null, null, null, true);
        }

        if ($updatedCustomer && $updatedAddress) {
            $updated = true;
        }

        return $updated;
    }

    protected function updateAddressInfo($customer, $addressId, $addressInfo)
    {
        $addressId = (int) $addressId;
        $address = new Address($addressId);
        $addresses = $customer->getSimpleAddresses();

        $address->alias = Utils::getAddressAlias($addresses);
        foreach ($addressInfo as $key => $value) {
            $address->{$key} = $value;
        }
        $address->update();

        return true;
    }

    // /**
    //  * @param AvardaSession $session
    //  * @param bool $validateOrder
    //  *
    //  * @throws AvardaException
    //  * @throws PrestaShopException
    //  *
    //  * @return string
    //  */
    // public function validatePayment(AvardaSession $session, $validateOrder = true)
    // {
    //     try {
    //         // resolve cart
    //         $cart = Context::getContext()->cart;
    //         if ($cart->id != $session->id_cart) {
    //             $cart = new Cart($session->id_cart);
    //         }

    //         $info = $this->getApi()->getPaymentInfo($session->purchase_id);
    //         $jsonInfo = json_decode(json_encode($info), false);

    //         if ($jsonInfo->mode === 'B2C') {
    //             $purchaseMode = 'B2C';
    //             $mode = $jsonInfo->b2C;
    //         } else {
    //             $purchaseMode = 'B2B';
    //             $mode = $jsonInfo->b2B;
    //         }

    //         $checkoutSite = $jsonInfo->checkoutSite;
    //         $userInputs = $mode->userInputs;
    //         $email = $userInputs->email;

    //         $step = $mode->step;

    //         $session->info = json_encode($info);
    //         $session->save();

    //         $state = $step->current;

    //         if ($state !== Api::STATUS_COMPLETED) {
    //             throw new AvardaException('Payment validation failed: invalid status ' . $state);
    //         }

    //         $invoiceAddressInfo = Utils::extractAddressInfo('invoicingAddress', $checkoutSite, $mode, $purchaseMode);
    //         $deliverAddressInfo = Utils::extractAddressInfo('deliveryAddress', $checkoutSite, $mode, $purchaseMode);

    //         // Checking the delivery address. If null values, the invoice address is used as delivery address.
    //         if (!Utils::validAddressInfo($deliverAddressInfo)) {
    //             $deliverAddressInfo = $invoiceAddressInfo;
    //         }

    //         // check if an user exists with given email
    //         $oldCustomerID = Customer::customerExists($email, true, false);

    //         if ($oldCustomerID) {
    //             $this->connectToOldCustomer($oldCustomerID, $session, $cart);
    //             $customer = new Customer($oldCustomerID);
    //         } else {
    //             $customer_id = $cart->id_customer;
    //             $customer = new Customer($customer_id);
    //         }

    //         if (!Validate::isLoadedObject($customer)) {
    //             throw new AvardaException('No customer associated with cart');
    //         }

    //         // resolve delivery address
    //         $update = false;
    //         $newDeliveryId = static::resolveAddress($customer, $cart->id_address_delivery, $deliverAddressInfo);
    //         if ($cart->id_address_delivery != $newDeliveryId) {
    //             Db::getInstance()->execute('UPDATE `' . _DB_PREFIX_ . 'cart_product` SET `id_address_delivery` = ' . $newDeliveryId . ' WHERE  `id_cart` = ' . $cart->id . ' AND `id_address_delivery` = ' . $cart->id_address_delivery);
    //             Db::getInstance()->execute('UPDATE `' . _DB_PREFIX_ . 'customization` SET `id_address_delivery` = ' . $newDeliveryId . ' WHERE  `id_cart` = ' . $cart->id . ' AND `id_address_delivery` = ' . $cart->id_address_delivery);
    //             $cart->id_address_delivery = $newDeliveryId;
    //             $cart->delivery_option = json_encode([$cart->id_address_delivery => $cart->id_carrier . ',']);
    //             $update = true;
    //         }

    //         // resolve invoice address
    //         $newInvoiceId = static::resolveAddress($customer, $cart->id_address_invoice, $invoiceAddressInfo);
    //         if ($cart->id_address_invoice != $newInvoiceId) {
    //             $cart->id_address_invoice = $newInvoiceId;
    //             $update = true;
    //         }

    //         // save cart if it has been updated
    //         if ($update) {
    //             $cart->update();
    //             $this->validatePayment($session, false);
    //         }

    //         if ($validateOrder) {
    //             Cart::resetStaticCache();

    //             $amountPaid = $jsonInfo->totalPrice;
    //             $total = (float) $cart->getOrderTotal(true, Cart::BOTH);
    //             if ($amountPaid > $total + 0.10 || $amountPaid < $total - 0.10) {
    //                 $total = $amountPaid;
    //             }

    //             $settings = $this->module->getSettings();
    //             // validate order
    //             $this->module->validateOrder(
    //                 (int) $cart->id,
    //                 (int) $settings->getCompletedStatus(),
    //                 $total,
    //                 'Avarda: ' . $jsonInfo->paymentMethods->selectedPayment->type,
    //                 $this->module->l('Payment accepted'),
    //                 ['transaction_id' => $session->purchase_id],
    //                 $cart->id_currency,
    //                 false,
    //                 $customer->secure_key
    //             );

    //             // update session
    //             $session->status = 'completed';
    //             $session->id_order = (int) $this->module->currentOrder;
    //             $session->save();

    //             //add order reference to Avarda
    //             $response = $this->getApi()->putExtraIdentifiers($this->module->currentOrderReference, $session->purchase_id);

    //             $order = new Order((int) $this->module->currentOrder);

    //             $orderManager = $this->module->getOrderManager();
    //             $orderManager->authorize($order, $total);

    //             if ($settings->getDeliveryStatus() === $settings->getCompletedStatus()) {
    //                 $orderManager->delivery($order);
    //             }
    //         } else {
    //             return true;
    //         }
    //     } catch (Exception $e) {
    //         //TODO: comment this quickfix out when debugging and find out how to match the delivery_option from DB with prestashop delivery_option_list to fix this error
    //         if (strpos($e, 'Notice: Undefined offset:') || strpos($e, 'Notice: Undefined variable: order')) {
    //             //do nothing here, go to confirmation page
    //         } else {
    //             Logger::addLog('validatePayment(): error - ' . $e, 3, null, null, null, true);

    //             return false;
    //         }
    //     }

    //     // redirect to confirmation page
    //     return Context::getContext()->link->getPageLink(
    //         'order-confirmation',
    //         true,
    //         null,
    //         [
    //             'id_cart' => $this->context->cart->id,
    //             'id_module' => $this->module->id,
    //             'key' => $order->secure_key,
    //         ]
    //     );
    // }

    /**
     * @param Customer $customer
     * @param $addressId
     * @param $addressInfo
     *
     * @return int
     *
     * @throws PrestaShopException
     */
    protected function resolveAddress(Customer $customer, $addressId, $addressInfo)
    {
        $addressId = (int) $addressId;

        if (!Utils::validAddressInfo($addressInfo)) {
            return $addressId;
        }

        $addresses = $customer->getSimpleAddresses();

        // check default address first
        if (isset($addresses[$addressId]) && Utils::addressMatches($addresses[$addressId], $addressInfo)) {
            return $addressId;
        }

        // check existing addresses
        foreach ($addresses as $id => $address) {
            if (Utils::addressMatches($address, $addressInfo)) {
                return (int) $id;
            }
        }

        // no address was found.
        $address = new Address();
        $address->id_customer = $customer->id;
        $address->alias = Utils::getAddressAlias($addresses);
        foreach ($addressInfo as $key => $value) {
            $address->{$key} = $value;
        }
        $address->save();

        return (int) $address->id;
    }

    /**
     * @param $error
     *
     * @throws Exception
     */
    protected function setError($error)
    {
        $this->context->smarty->assign('avardaError', $error);
    }

    /**
     * @throws Exception
     */
    protected function renderCart()
    {
        $presenter = new CartPresenter();
        $this->context->smarty->assign('cart', $presenter->present($this->context->cart, true));
    }

    /**
     * @param Cart $cart
     *
     * @return bool
     */
    protected static function validCart($cart)
    {
        return $cart && $cart->nbProducts() > 0;
    }

    /**
     * @return string
     */
    protected function getJavascriptUrl()
    {
        /*
        return $this->module->getSettings()->isTestMode()
            ? 'https://stage.avarda.org/CheckOut2/Scripts/CheckOutClient.js'
            : 'https://online.avarda.org/CheckOut2/Scripts/CheckOutClient.js';
        */
    }

    protected function getShippingOptions($cart, $address)
    {
        $shippingOptions = [];
        $useDefault = true;
        if ($cart->id_carrier) {
            $useDefault = false;
        }
        if (static::validCart($cart)) {
            foreach ($cart->getDeliveryOptionList() as $carriers) {
                foreach ($carriers as $carrier) {
                    foreach ($carrier['carrier_list'] as $carrierOption) {
                        $carrierObject = $carrierOption['instance'];
                        $shippingOption = [];
                        $shippingOption['logo'] = $carrierOption['logo'];
                        $shippingOption['id'] = $carrierObject->id;
                        if (($cart->id_carrier == $carrierObject->id && !$useDefault) || ($carrierObject->id == (int) Configuration::get('PS_CARRIER_DEFAULT') && $useDefault)) {
                            $shippingOption['preselected'] = true;
                        } else {
                            $shippingOption['preselected'] = false;
                        }
                        $shippingOption['name'] = $carrierObject->name;
                        $shippingOption['delay'] = $carrierObject->delay[(int) $cart->id_lang];
                        $shippingOption['price'] = $carrier['total_price_with_tax'];
                        $taxAmount = (($carrier['total_price_with_tax'] - $carrier['total_price_without_tax']));
                        $shippingOption['tax_amount'] = $taxAmount;
                        $shipping_tax_rate = $carrierObject->getTaxesRate($address);
                        $shippingOption['tax_rate'] = $shipping_tax_rate * 100;
                        $shippingOptions[] = $shippingOption;
                    }
                }
            }
        }

        return $shippingOptions;
    }

    protected function getShippingOptionIDs($cart)
    {
        $shippingOptions = [];
        if (static::validCart($cart)) {
            foreach ($cart->getDeliveryOptionList() as $carriers) {
                foreach ($carriers as $carrier) {
                    foreach ($carrier['carrier_list'] as $carrierOption) {
                        $carrierObject = $carrierOption['instance'];
                        $shippingOptions[] = strval($carrierObject->id);
                    }
                }
            }
        } else {
            $shippingOptions = false;
        }

        return $shippingOptions;
    }

    // protected function createCustomer($session, $params)
    // {
    //     try {
    //         // initialize key parameters
    //         $cart = new Cart($session->id_cart);
    //         $customer_id = $cart->id_customer;
    //         $customer = new Customer($customer_id);

    //         // TODO make into function if time
    //         $info = $this->getApi()->getPaymentInfo($session->purchase_id);
    //         $jsonInfo = json_decode(json_encode($info), false);

    //         if ($jsonInfo->mode === 'B2C') {
    //             $purchaseMode = 'B2C';
    //             $mode = $jsonInfo->b2C;
    //             $invoicingAddress = $mode->invoicingAddress;
    //             $invoicingFirstname = $invoicingAddress->firstName;
    //             $invoicingLastname = $invoicingAddress->lastName;
    //         } else {
    //             $purchaseMode = 'B2B';
    //             $mode = $jsonInfo->b2B;
    //             $invoicingAddress = $mode->invoicingAddress;

    //             // For B2B there's necessarily no first and last name. These can't be empty in PrestaShop.
    //             $invoicingFirstname = '-';
    //             $invoicingLastname = '-';

    //             if (isset($mode->customerInfo->firstName) && !empty($mode->customerInfo->firstName)) {
    //                 $invoicingFirstname = $mode->customerInfo->firstName;
    //             }

    //             if (isset($mode->customerInfo->lastName) && !empty($mode->customerInfo->lastName)) {
    //                 $invoicingLastname = $mode->customerInfo->lastName;
    //             }
    //         }

    //         $checkoutSite = $jsonInfo->checkoutSite;
    //         $userInputs = $mode->userInputs;
    //         $email = $userInputs->email;

    //         $step = $mode->step;

    //         // update info to session
    //         $session->info = json_encode($info);
    //         $session->save();

    //         $customerExists = false;

    //         // check if an user exists with given email
    //         $oldCustomerID = Customer::customerExists($email, true, false);

    //         if ($oldCustomerID) {
    //             $this->connectToOldCustomer($oldCustomerID, $session, $cart);
    //             $customer = new Customer($oldCustomerID);
    //         } else {
    //             // if customer has dummy data, update customer info
    //             if ($customer->firstname == $this->dummyFirst) {
    //                 // Truncating '-' causes issues
    //                 if ($invoicingFirstname === '-' || $invoicingLastname === '-') {
    //                     $customer->firstname = '-';
    //                     $customer->lastname = '-';
    //                 } else {
    //                     $customer->firstname = Utils::truncateValue($invoicingFirstname, 32, true);
    //                     $customer->lastname = Utils::truncateValue($invoicingLastname, 32, true);
    //                 }
    //                 $customer->email = $email;
    //                 // save info
    //                 $customer->update();
    //             }

    //             if ($customer->is_guest) {
    //                 if ($session->create_customer) {
    //                     // customer wants to create user
    //                     $customer->is_guest = 0;
    //                     // save given password
    //                     $customer->passwd = $session->passwd;
    //                     $customer->update();
    //                     // remove password from session for security
    //                     $session->passwd = '';
    //                     $session->save();
    //                     // update customer context
    //                     $this->updateCustomerContext($customer, true);
    //                 }
    //             }

    //             if (!$customer->newsletter) {
    //                 if ($session->newsletter) {
    //                     $customer->newsletter = 1;
    //                     $customer->update();
    //                 }
    //             }
    //         }

    //         if ($session->order_message) {
    //             $message = new Message();
    //             $message->message = $session->order_message;
    //             $message->id_cart = (int) $cart->id;
    //             $message->id_customer = (int) $customer->id;
    //             $message->add();
    //         }

    //         // if payment is not complete, throw exception
    //         $state = $step->current;
    //         // $state = (int)$info['State'];
    //         if ($state !== Api::STATUS_COMPLETED) {
    //             throw new AvardaException('Payment validation failed: invalid status ' . $state);
    //         }

    //         // update addresses
    //         // get addresses
    //         $invoiceAddressInfo = Utils::extractAddressInfo('invoicingAddress', $checkoutSite, $mode, $purchaseMode);
    //         $deliverAddressInfo = Utils::extractAddressInfo('deliveryAddress', $checkoutSite, $mode, $purchaseMode);

    //         // Checking the delivery address. If null values, the invoice address is used as delivery address.
    //         if (!Utils::validAddressInfo($deliverAddressInfo)) {
    //             $deliverAddressInfo = $invoiceAddressInfo;
    //         }

    //         // update or create address information
    //         $address_id = Address::getFirstCustomerAddressId($customer->id);
    //         $this->updateAddressInfo($customer, $address_id, $invoiceAddressInfo);
    //         $deliverAddressId = static::resolveAddress($customer, $address_id, $deliverAddressInfo);
    //         // save current cart delivery address id for later check
    //         $oldAddressId = (int) $cart->id_address_delivery;
    //         // update address id's to cart
    //         $cart->id_address_invoice = $address_id;
    //         $cart->id_address_delivery = $deliverAddressId;
    //         // update cart info
    //         // selected carrier
    //         $cart->id_carrier = $session->id_carrier;
    //         // recycled package
    //         if ((int) Configuration::get('PS_RECYCLABLE_PACK')) {
    //             if ($session->is_recycled) {
    //                 $cart->recyclable = 1;
    //             }
    //         }
    //         // gift
    //         if ((int) Configuration::get('PS_GIFT_WRAPPING')) {
    //             if ($session->is_gift) {
    //                 $cart->gift = 1;
    //                 if ($session->gift_message) {
    //                     $cart->gift_message = $session->gift_message;
    //                 }
    //             }
    //         }
    //         // update cart
    //         $cart->update();

    //         // we need to update delivery address id also to tables cart_product and customization if it changed
    //         if ($oldAddressId != $deliverAddressId) {
    //             Db::getInstance()->execute('UPDATE `' . _DB_PREFIX_ . 'cart_product` SET `id_address_delivery` = ' . $deliverAddressId . ' WHERE  `id_cart` = ' . $cart->id . ' AND `id_address_delivery` = ' . $oldAddressId);
    //             Db::getInstance()->execute('UPDATE `' . _DB_PREFIX_ . 'customization` SET `id_address_delivery` = ' . $deliverAddressId . ' WHERE  `id_cart` = ' . $cart->id . ' AND `id_address_delivery` = ' . $oldAddressId);
    //         }

    //         $oldAddress = new Address($oldAddressId);
    //         $oldAddress->delete();
    //     } catch (Exception $e) {
    //         Logger::addLog('createCustomer(): error - ' . $e, 3, null, null, null, true);
    //     }

    //     return true;
    // }

    // /**
    //  * method used in regular checkout
    //  * check if user exists with given email
    //  * add old id into session info if does
    //  */
    // protected function compareCustomer($session)
    // {
    //     $info = $this->getApi()->getPaymentInfo($session->purchase_id);
    //     $jsonInfo = json_decode(json_encode($info), false);

    //     if ($jsonInfo->mode === 'B2C') {
    //         $mode = $jsonInfo->b2C;
    //     } else {
    //         $mode = $jsonInfo->b2B;
    //     }

    //     $userInputs = $mode->userInputs;
    //     $email = $userInputs->email;

    //     // update info to session
    //     $session->info = json_encode($info);
    //     $session->save();

    //     // check if an user exists with given email
    //     $oldCustomerID = Customer::customerExists($email, true, false);

    //     if ($oldCustomerID) {
    //         $info['Duplicate'] = $oldCustomerID;
    //         $session->info = json_encode($info);
    //         $session->save();
    //     }
    // }

    /**
     * function to crete a dummy guest customer for avarda no-login checkout
     *
     * @return $customer_id
     *                      -- if dummy customer is created succesfully, return customer id
     *                      -- return -1 if adding fails
     */
    protected function createDummyGuestCustomer($cart)
    {
        $customer = new Customer();
        // set customer as guest
        $customer->is_guest = 1;
        $firstname = $this->dummyFirst;
        $customer->firstname = Utils::truncateValue($firstname, 32, true);
        $lastname = $this->dummyLast;
        $customer->lastname = Utils::truncateValue($lastname, 32, true);
        // TEST - does guest account require email?
        $email = $this->dummyEmail;
        $customer->email = $email;
        $customer->passwd = $this->dummyPassword;
        // add guest group as customer default group
        $this->id_default_group = (int) Configuration::get('PS_GUEST_GROUP');
        // newsletter and optin 0, since we have not asked any permissions
        $customer->newsletter = 0;
        $customer->optin = 0;
        // set profile as active
        $customer->active = 1;
        if ($customer->add()) {
            // customer was added succesfully
            // add newly created customer id into return value
            $customer_id = $customer->id;
            // log result
            $this->updateCustomerContext($customer);
            $this->createDummyAddress($customer);
        } else {
            // customer creation failed
            // set return value to -1
            $customer_id = -1;
            // log result
            Logger::addLog('Dummy Guest Customer adding failed', 2, null, null, null, true);
        }

        return $customer_id;
    }

    protected function createDummyAddress($customer)
    {
        try {
            $address = new Address();
            // customer id
            $address->id_customer = $customer->id;
            // add dummy data to required fields
            $address->alias = $this->dummyAlias;
            $address->firstname = $customer->firstname;
            $address->lastname = $customer->lastname;
            $address->address1 = $this->dummyAddress;
            $address->postcode = $this->dummyPostcode;
            $address->city = $this->dummyCity;
            $address->id_country = $this->dummyCountry;

            if ($address->add()) {
            } else {
                Logger::addLog('Dummy Address adding failed', 2, null, null, null, true);
            }
        } catch (Exception $e) {
            Logger::addLog('createDummyAddress() - error: ' . $e, 2, null, null, null, true);
        }
    }

    protected function updateCarrier($session, $params)
    {
        $cart = new Cart($session->id_cart);

        $cart->id_carrier = (int) $params['idCarrier'];
        $cart->delivery_option = json_encode([$cart->id_address_delivery => $cart->id_carrier . ',']);
        $wrappingFeesTaxInc = $cart->getGiftWrappingPrice(true, $cart->id_address_delivery);
        if (!$cart->update()) {
            Logger::addLog('updateCarrier - carrier not updated', 3, null, null, null, true);
        }

        $deliveryDescription = $this->module->l('Delivery cost (Including taxes)');
        $cartInfo = Utils::getCartInfo($cart, $deliveryDescription);
        $itemsArray = [
            'items' => $cartInfo['items'],
        ];

        $this->getApi($session->global)->updateItems($session->purchase_id, $itemsArray);

        return 'refresh';
    }

    protected function updateWrapping($session, $params)
    {
        $cart = new Cart($session->id_cart);
        //Because this check from url params, we have to check it as a string
        if ($params['gift'] == 'true') {
            $cart->gift = 1;
        } else {
            $cart->gift = 0;
        }
        if (!$cart->update()) {
            Logger::addLog('updateWrapping - wrapping not updated', 3, null, null, null, true);
        }
    }

    // protected function connectToOldCustomer($id_old_customer, $session, $cart, $order = null)
    // {
    //     try {
    //         //TODO: why do we arrive here when customer has null information, it causes the error?
    //         $session->id_customer = $id_old_customer;
    //         $session->save();
    //         $id_customer = $cart->id_customer;
    //         $customer = new Customer($id_old_customer);
    //         // update address
    //         $id_address_delivery = $cart->id_address_delivery;
    //         $id_address_invoice = $cart->id_address_invoice;
    //         if ($id_address_delivery == $id_address_invoice) {
    //             $address = new Address($id_address_delivery);
    //             $address->id_customer = $id_old_customer;
    //             if ($address->update()) {
    //             } else {
    //                 Logger::addLog('connectToOldCustomer(): failed to update customer into address', 3, null, null, null, true);
    //             }
    //         } else {
    //             $address_delivery = new Address($id_address_delivery);
    //             $address_delivery->id_customer = $id_old_customer;
    //             if ($address_delivery->update()) {
    //             } else {
    //                 Logger::addLog('connectToOldCustomer(): failed to update customer into delivery address', 3, null, null, null, true);
    //             }
    //             $address_invoice = new Address($id_address_invoice);
    //             $address_invoice->id_customer = $id_old_customer;
    //             if ($address_invoice->update()) {
    //             } else {
    //                 Logger::addLog('connectToOldCustomer(): failed to update customer into invoice address', 3, null, null, null, true);
    //             }
    //         }
    //         // update cart and context with old customer data
    //         $cart->id_customer = $id_old_customer;
    //         $cart->secure_key = $customer->secure_key;
    //         if ($cart->update()) {
    //             $this->context->cart->id_customer = $cart->id_customer;
    //             $this->context->cart->secure_key = $cart->secure_key;
    //             if ($this->context->cart->update()) {
    //             } else {
    //                 Logger::addLog('connectToOldCustomer(): failed to update customer into cart context', 3, null, null, null, true);
    //             }
    //         } else {
    //             Logger::addLog('connectToOldCustomer(): failed to update customer into cart', 3, null, null, null, true);
    //         }
    //         // update order with old customer data
    //         if ($order) {
    //             $order->id_customer = $id_old_customer;
    //             $order->secure_key = $customer->secure_key;
    //             if ($order->update()) {
    //             } else {
    //                 Logger::addLog('connectToOldCustomer(): failed to update customer into corder', 3, null, null, null, true);
    //             }
    //         }
    //         // delete ghost customer, since we don't need it anymore
    //         if ($id_old_customer != $id_customer) {
    //             $this->updateCustomerContext($customer, false);
    //             $ghostCustomer = new Customer($id_customer);
    //             if ($ghostCustomer->delete()) {
    //             } else {
    //                 Logger::addLog('connectToOldCustomer(): failed to delete ghost customer', 3, null, null, null, true);
    //             }
    //         }

    //         if ($customer->is_guest) {
    //             if ($session->create_customer) {
    //                 // customer wants to create user
    //                 $customer->is_guest = 0;
    //                 // save given password
    //                 $customer->passwd = $session->passwd;
    //                 $customer->update();
    //                 // remove password from session for security
    //                 $session->passwd = '';
    //                 $session->save();
    //                 // update customer context
    //                 $this->updateCustomerContext($customer, true);
    //             }
    //         }

    //         if (!$customer->newsletter) {
    //             if ($session->newsletter) {
    //                 $customer->newsletter = 1;
    //                 $customer->update();
    //             }
    //         }
    //     } catch (Exception $e) {
    //         //TODO: comment this out when debugging and fix this
    //         if (strpos($e, 'Property Address->id_country is empty.')) {
    //             //do nothing here, continue validation
    //         } else {
    //             Logger::addLog('connectToOldCustomer() - error: ' . $e, 3, null, null, null, true);
    //         }
    //     }
    // }
}
