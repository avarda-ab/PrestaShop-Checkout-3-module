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

namespace AvardaPayments;

use Configuration;
use Context;
use Exception;
use Language;
use Logger;
use Tools;

require_once _PS_MODULE_DIR_ . 'avardapayments/classes/settings.php';

class Api
{
    /**
     * @var string
     */
    private $server;

    /**
     * @var string
     */
    private $auth;

    const STATUS_NEW = 0;
    const STATUS_BEING_PROCESSED = 1;
    const STATUS_COMPLETED = 'Completed';
    const STATUS_ERROR = 3;
    const STATUS_WAITING_FOR_SIGNICAT = 4;
    const STATUS_SESSION_TIMED_OUT = 5;
    const STATUS_WAITING_FOR_CARD_PAYMENTS = 6;
    const STATUS_WAITING_FOR_BANK_ID = 7;
    const STATUS_CANCELLED = 8;
    const STATUS_WAITING_FOR_FINNISH_DIRECT_PAYMENT = 9;
    const STATUS_WAITING_CREDIT_APPROVAL = 10;
    const STATUS_CREDIT_NOT_APPROVED = 11;
    const STATUS_WAITING_FOR_SWISH_PAYMENT = 12;
    const STATUS_PAYMENT_HANDLED_BY_MERCHANT = 13;

    const PAYMENT_METHOD_INVOICE = 0;
    const PAYMENT_METHOD_LOAN = 1;
    const PAYMENT_METHOD_CARD = 2;
    const PAYMENT_METHOD_DIRECT_PAYMENT = 3;
    const PAYMENT_METHOD_PART_PAYMENT = 4;
    const PAYMENT_METHOD_SWISH = 5;
    const PAYMENT_METHOD_HIGH_LOAN_AMOUNT = 6;
    const PAYMENT_METHOD_PAYPAL = 7;
    const PAYMENT_METHOD_PAY_ON_DELIVERY = 8;
    const PAYMENT_METHOD_B2B_INVOICE = 9;
    const PAYMENT_METHOD_MASTERPASS = 11;
    const PAYMENT_METHOD_MOBILEPAY = 12;
    const PAYMENT_METHOD_VIPPS = 13;

    /**
     * Api constructor.
     *
     * @param string $mode
     * @param string $code
     * @param string $password
     *
     * @throws AvardaException
     */
    public function __construct($mode, $client_id, $client_secret)
    {
        $this->server = $mode === 'test'
            ? 'https://avdonl-s-checkout.avarda.org'
            : 'https://avdonl-p-checkout.avarda.org';
        if (!$client_id || !$client_secret) {
            throw new AvardaException('Credentials are not set');
        }
        $this->auth = ['clientId' => $client_id, 'clientSecret' => $client_secret];
    }

    /**
     * executes InitializePayment api method
     *
     * https://docs.avarda.com/checkout-3/how-to-get-started//
     *
     * @param array $customerInfo
     *
     * @return array|string
     *
     * @throws AvardaException
     */
    public function initializePayment($customerInfo)
    {
        $requestPayload = $this->makeb2CRequestPayload($customerInfo);

        return $this->execute('/api/partner/payments', $requestPayload, 'POST', 'initializePayment()');
    }

    private static function getCustomerType()
    {
        $type = '';
        // b2b setting not on in prestashop
        if (!Configuration::get('PS_B2B_ENABLE')) {
            $type = 'Private';
        } else {
            $type = 'Company';
        }

        return $type;
    }

    private function makeb2CRequestPayload($customerInfo)
    {
        global $cookie;
        $id_lang = $cookie->id_lang;
        $languageArr = Language::getLanguage($id_lang);
        $language = locale_get_display_language($languageArr['locale'], 'en');
        $itemsArr = [];

        $settings = new \AvardaPayments\Settings();
        $useOnePage = $settings->getUseOnePage();

        for ($i = 0; $i < count($customerInfo['items']); ++$i) {
            $singleItem = [
                'description' => $customerInfo['items'][$i]['description'],
                'amount' => $customerInfo['items'][$i]['amount'],
                'taxAmount' => $customerInfo['items'][$i]['taxAmount'],
            ];

            array_push($itemsArr, $singleItem);
        }

        $checkoutSetupOnePage = $this->getCheckoutSetup($language, 'B2C', 'Unchecked');
        $checkoutSetupNormal = $this->getCheckoutSetup($language, 'B2C', 'Hidden');

        if (!array_key_exists('InvoicingZip', $customerInfo)) {
            $customerInfo['InvoicingZip'] = '';
        }

        if (!array_key_exists('DeliveryZip', $customerInfo)) {
            $customerInfo['DeliveryZip'] = '';
        }

        if (!array_key_exists('Mail', $customerInfo)) {
            $customerInfo['Mail'] = '';
        }

        if ($useOnePage) {
            $request_payload = [
                'checkoutSetup' => $checkoutSetupOnePage,
                    'items' => $itemsArr,
                    'b2C' => [
                    'invoicingAddress' => [
                        'zip' => $customerInfo['InvoicingZip'],
                    ],
                    'deliveryAddress' => [
                        'zip' => $customerInfo['DeliveryZip'],
                        'type' => 'Default',
                    ],
                    'userInputs' => [
                        'email' => $customerInfo['Mail'],
                    ],
                ],
            ];
        } else {
            $phoneNumber = '';
            $invoicingAddressLine2 = null;
            $deliveryAddressLine2 = null;

            if ($customerInfo['Phone'] !== '') {
                $phoneNumber = $customerInfo['Phone'];
            }

            if (array_key_exists('InvoicingAddressLine2', $customerInfo)) {
                $invoicingAddressLine2 = $customerInfo['InvoicingAddressLine2'];
            }

            if (array_key_exists('DeliveryAddressLine2', $customerInfo)) {
                $deliveryAddressLine2 = $customerInfo['DeliveryAddressLine2'];
            }

            // Country is not required, documentation is wrong
            $request_payload = [
                'checkoutSetup' => $checkoutSetupNormal,
                    'items' => $itemsArr,
                    'b2C' => [
                    'invoicingAddress' => [
                        'firstName' => $customerInfo['InvoicingFirstName'],
                        'lastName' => $customerInfo['InvoicingLastName'],
                        'address1' => $customerInfo['InvoicingAddressLine1'],
                        'address2' => $invoicingAddressLine2,
                        'zip' => $customerInfo['InvoicingZip'],
                        'city' => $customerInfo['InvoicingCity'],
                    ],
                    'deliveryAddress' => [
                        'firstName' => $customerInfo['DeliveryFirstName'],
                        'lastName' => $customerInfo['DeliveryLastName'],
                        'address1' => $customerInfo['DeliveryAddressLine1'],
                        'address2' => $deliveryAddressLine2,
                        'zip' => $customerInfo['DeliveryZip'],
                        'city' => $customerInfo['DeliveryCity'],
                    ],
                    'userInputs' => [
                        'phone' => $phoneNumber,
                        'email' => $customerInfo['Mail'],
                                        ],
                ],
            ];
        }

        return $request_payload;
    }

    public function getCheckoutSetup($language, $mode, $differentDeliveryAddress)
    {
        $context = Context::getContext();

        $checkoutSetup = [
            'language' => $language,
            'mode' => 'B2C',
            'displayItems' => true,
            'differentDeliveryAddress' => $differentDeliveryAddress,
            'showThankYouPage' => false,
        ];

        $localAddresses = [
            'localhost',
            '127.0.0.1',
            '::1',
        ];

        $isLocalhost = in_array($_SERVER['SERVER_NAME'], $localAddresses);

        if (!$isLocalhost) {
            $name = $context->controller->module->name;
            $link = $context->link->getModuleLink($name, 'notification');
            $checkoutSetup['completedNotificationUrl'] = $link;
        }

        return $checkoutSetup;
    }

    // Used for Avarda Checkout form embed. Using the language if it's supported, otherwise default to English.
    public function getLanguageById($idLang)
    {
        $languageArr = Language::getLanguage($idLang);
        $language = locale_get_display_language($languageArr['language_code'], 'en');

        $avardaFormSupportedLanguages = [
            'Danish',
            'English',
            'Estonian',
            'Finnish',
            'Norwegian',
            'Swedish',
        ];

        if (!in_array($language, $avardaFormSupportedLanguages)) {
            $language = 'English';
        }

        return $language;
    }

    public function getApiEnv()
    {
        $env = '';
        if ($this->server === 'https://avdonl-s-checkout.avarda.org') {
            $env = 'test';
        } elseif ($this->server === 'https://avdonl-p-checkout.avarda.org') {
            $env = 'prod';
        } else {
            throw new AvardaException('API environment not found');
        }

        return $env;
    }

    /**
     * executes UpdateItems api method
     *
     * https://docs.avarda.com/checkout-3/more-features/update-items/
     *
     * @param string $purchaseId
     * @param array $items
     *
     * @return array|string
     *
     * @throws AvardaException
     */
    public function updateItems($purchaseId, $items)
    {
        return $this->execute('/api/partner/payments/' . $purchaseId . '/items', $items, 'PUT', 'updateItems()');
    }

    public function updateDeliveryAddress($purchaseId, $customerInfo)
    {
        $type = $this->getCustomerType();
        //TODO: get all the necessary information here
        $address = [
            'differentDeliveryAddress' => 'Unchecked',
            'deliveryAddress' => [
                'address1' => $customerInfo['address1'],
                'address2' => $customerInfo['address2'],
                'zip' => $customerInfo['postcode'],
                'city' => $customerInfo['city'],
                'country' => 'FI',
                'firstname' => $customerInfo['firstname'],
                'lastname' => $customerInfo['lastname'],
                'type' => $type,
            ],
        ];

        return $this->execute('/api/partner/payments/' . $purchaseId . '/address', $address, 'PUT');
    }

    /**
     * @param string $purchaseId
     *
     * @return array|string
     *
     * @throws AvardaException
     */
    public function getPaymentInfo($purchaseId)
    {
        return $this->execute('/api/partner/payments/' . $purchaseId, null, 'GET', 'getPaymentInfo()');
    }

    public function testApiCredentials($clientId, $clientSecret)
    {
        $authCredentials = [
            'clientId' => $clientId,
            'clientSecret' => $clientSecret,
        ];

        $accessToken = $this->authenticate($authCredentials);
        if (!empty($accessToken)) {
            return true;
        } else {
            throw new AvardaException('Authentication failed');
        }
    }

    /**
     * @param string $orderReference
     * @param string $purchaseId
     * @param $items
     * @param null $trackingCode
     * @param null $posId
     *
     * @return bool
     *
     * @throws AvardaException
     */
    public function createPurchaseOrder($orderReference, $purchaseId, $items, $trackingCode = null, $posId = null)
    {
        //TODO: why doesn't the orderReference appear anywhere?
        $payload = [
            'Items' => $items,
            'OrderReference' => $orderReference,
            'TranId' => Tools::passwdGen(30),
        ];

        if (!empty($trackingCode)) {
            $payload['TrackingCode'] = $trackingCode;
        }
        if (!empty($posId)) {
            $payload['PosId'] = $posId;
        }

        return $this->execute('/api/partner/payments/' . $purchaseId . '/order', $payload, 'POST', 'createPurchaseOrder');
    }

    public function putExtraIdentifiers($orderReference, $purchaseId)
    {
        $payload = [
                'orderReference' => $orderReference,
            ];

        return $this->execute('/api/partner/payments/' . $purchaseId . '/extraidentifiers', $payload, 'PUT', 'putExtraIdentifiers');
    }

    /* Old code that might be useful
    public function createPurchaseOrder($orderReference, $purchaseId, $items, $trackingCode=null, $posId=null)
    {
        $payload = [
            'ExternalId' => $purchaseId,
            'Items' => $items,
            'OrderReference' => $orderReference,
            'TranId' => Tools::passwdGen(30),
        ];
        if ($trackingCode) {
            $payload['TrackingCode'] = $trackingCode;
        }
        if ($posId) {
            $payload['PosId'] = $posId;
        }
        $errors = $this->execute('/CheckOut2Api/Commerce/PurchaseOrder', $payload, false);
        return self::processErrors($errors);
    }

    /**
     * @param string $purchaseId
     * @param string $reason
     * @return bool
     * @throws AvardaException
     */
    public function cancelPayment($purchaseId, $reason)
    {
        $payload = [
            'reason' => $reason,
        ];

        return $this->execute('/api/partner/payments/' . $purchaseId . '/cancel', $payload, 'POST', 'cancelPayment');
    }

    public function refundAmount($orderReference, $purchaseId, $amount)
    {
        $payload = [
            'orderReference' => $orderReference,
            'tranId' => Tools::passwdGen(30),
            'amount' => $amount,
        ];

        return $this->execute('/api/partner/payments/' . $purchaseId . '/refund', $payload, 'POST', 'refundAmount');
    }

    public function refundRemaining($orderReference, $purchaseId)
    {
        $payload = [
            'orderReference' => $orderReference,
            'tranId' => Tools::passwdGen(30),
        ];

        return $this->execute('/api/partner/payments/' . $purchaseId . '/refund', $payload, 'POST');
    }

    // Old code that might be useful
    /**
     * @param string $orderReference
     * @param string $purchaseId
     * @param float $amount
     *
     * @return bool
     *
     * @throws AvardaException
     */
    /*
        public function refundAmount($orderReference, $purchaseId, $amount)
        {
            $payload = [
                'ExternalId' => $purchaseId,
                'Amount' => $amount,
                'OrderReference' => $orderReference,
                'TranId' => Tools::passwdGen(30),
            ];
            $errors = $this->execute('/CheckOut2Api/Commerce/Refund', $payload, false);
            return self::processErrors($errors);
        }
    /*
        /**
         * @param string $orderReference
         * @param string $purchaseId
         * @param array $items
         * @return boolean
         * @throws AvardaException
         */

    /**
     * @param $orderReference
     * @param $purchaseId
     *
     * @return bool
     *
     * @throws AvardaException
     */
    /*
        public function refundRemaining($orderReference, $purchaseId)
        {
            $payload = [
                'ExternalId' => $purchaseId,
                'OrderReference' => $orderReference,
                'TranId' => Tools::passwdGen(30),
            ];
            $errors = $this->execute('/CheckOut2Api/Commerce/RefundRemaining', $payload, false);
            return self::processErrors($errors);
        }
    */

    public function returnItems($orderReference, $purchaseId, $items)
    {
        $payload = [
            'items' => $items,
            'orderReference' => $orderReference,
            'tranId' => Tools::passwdGen(30),
        ];

        return $this->execute('/api/partner/payments/' . $purchaseId . '/return', $payload, 'POST', 'returnItems');
    }

    /**
     * @param $endpoint
     * @param $body
     * @param bool $jsonResponse
     *
     * @return string|array
     *
     * @throws AvardaException
     */
    public function execute($endpoint, $body, $method = 'POST', $apiCall = null)
    {
        $accessToken = $this->authenticate();
        if (empty($accessToken)) {
            throw new AvardaException('API error: authentication error');
            exit();
        }

        if ($method === 'POST') {
            $fetch = new Fetch($this->server . $endpoint, $method, $body);

            $fetch->setHeader('Authorization', 'Bearer ' . $accessToken);
            $responseArray = $fetch->execute();
            if ($responseArray['httpStatus'] > 299) {
                throw new AvardaException('API error in call: ' . $apiCall . ' Error: ' . $responseArray['response']);
                $response = null;
            } elseif (empty($responseArray['response']) && $apiCall === 'refundAmount') {
                $response = $responseArray['response'];

                return $response;
            } elseif (empty($responseArray['response']) && $apiCall === 'createPurchaseOrder') {
                $response = $responseArray['response'];

                return $response;
            } elseif (empty($responseArray['response']) && $apiCall === 'cancelPayment') {
                $response = $responseArray['response'];

                return $response;
            } elseif (empty($responseArray['response']) && $apiCall === 'returnItems') {
                $response = $responseArray['response'];

                return $response;
            } elseif (empty($responseArray['response'])) {
                throw new AvardaException('API error in call: ' . $apiCall . ' Error: empty response', 3, null, null, null, true);
                $response = null;
            } else {
                $response = json_decode($responseArray['response'], true);
            }

            return $response;
        } elseif ($method === 'PUT') {
            $fetch = new Fetch($this->server . $endpoint, $method, $body);

            $fetch->setHeader('Authorization', 'Bearer ' . $accessToken);
            $responseArray = $fetch->execute();

            if ($responseArray['httpStatus'] > 299) {
                throw new AvardaException('API error in call: ' . $apiCall . ' Error: ' . $responseArray['response']);
                $response = null;
            } elseif (!empty($responseArray['response'])) {
                $response = json_decode($responseArray['response'], true);
            } else {
                $response = $responseArray['response'];
            }

            return $response;
        } elseif ($method === 'GET') {
            $fetch = new Fetch($this->server . $endpoint, $method, $body);

            $fetch->setHeader('Authorization', 'Bearer ' . $accessToken);
            $responseArray = $fetch->execute();

            if ($responseArray['httpStatus'] > 299) {
                throw new AvardaException('API error in call: ' . $apiCall . ' Error: ' . $responseArray['response']);
                $response = null;
            } else {
                $response = json_decode($responseArray['response'], true);
            }

            return $response;
        }
    }

    private function authenticate()
    {
        $accessToken = '';

        // Send POST request and save "Partner access token"
        $request_url = $this->server . '/api/partner/tokens';

        $request_payload = $this->auth;

        $options = [
            'http' => [
                'header' => "Content-type: application/json\r\n",
                'method' => 'POST',
                'content' => json_encode($request_payload),
            ],
        ];

        try {
            $context = stream_context_create($options);
            $result = file_get_contents($request_url, false, $context);
            if ($result === false) { /* Handle error */
                Logger::addLog('Authentication error', 1, null, null, null, true);
            } else {
                $json_data = json_decode($result);
                $accessToken = $json_data->token;
            }
        } catch (exception $e) {
            Logger::addLog('Unexpected error happened during API authentication ' . $e, 1, null, null, null, true);
        }

        return $accessToken;
    }

    /**
     * @param $status
     *
     * @return string
     */
    public static function getStatusName($status)
    {
        switch ($status) {
            case static::STATUS_NEW:
                return 'New';
            case static::STATUS_BEING_PROCESSED:
                return 'BEING_PROCESSED';
            case static::STATUS_COMPLETED:
                return 'COMPLETED';
            case static::STATUS_ERROR:
                return 'ERROR';
            case static::STATUS_WAITING_FOR_SIGNICAT:
                return 'WAITING_FOR_SIGNICAT';
            case static::STATUS_SESSION_TIMED_OUT:
                return 'SESSION_TIMED_OUT';
            case static::STATUS_WAITING_FOR_CARD_PAYMENTS:
                return 'WAITING_FOR_CARD_PAYMENTS';
            case static::STATUS_WAITING_FOR_BANK_ID:
                return 'WAITING_FOR_BANK_ID';
            case static::STATUS_CANCELLED:
                return 'CANCELLED';
            case static::STATUS_WAITING_FOR_FINNISH_DIRECT_PAYMENT:
                return 'WAITING_FOR_FINNISH_DIRECT_PAYMENT';
            case static::STATUS_WAITING_CREDIT_APPROVAL:
                return 'WAITING_CREDIT_APPROVAL';
            case static::STATUS_CREDIT_NOT_APPROVED:
                return 'CREDIT_NOT_APPROVED';
            case static::STATUS_WAITING_FOR_SWISH_PAYMENT:
                return 'WAITING_FOR_SWISH_PAYMENT';
            case static::STATUS_PAYMENT_HANDLED_BY_MERCHANT:
                return 'PAYMENT_HANDLED_BY_MERCHANT';
            default:
                return 'UNKNOWN';
        }
    }

    public static function getPaymentMethodName($method)
    {
        switch ($method) {
            case static::PAYMENT_METHOD_INVOICE:
                return 'INVOICE';
            case static::PAYMENT_METHOD_LOAN:
                return 'LOAN';
            case static::PAYMENT_METHOD_CARD:
                return 'CARD';
            case static::PAYMENT_METHOD_DIRECT_PAYMENT:
                return 'DIRECT_PAYMENT';
            case static::PAYMENT_METHOD_PART_PAYMENT:
                return 'PART_PAYMENT';
            case static::PAYMENT_METHOD_SWISH:
                return 'SWISH';
            case static::PAYMENT_METHOD_HIGH_LOAN_AMOUNT:
                return 'HIGH_LOAN_AMOUNT';
            case static::PAYMENT_METHOD_PAYPAL:
                return 'PAYPAL';
            case static::PAYMENT_METHOD_PAY_ON_DELIVERY:
                return 'PAY_ON_DELIVERY';
            case static::PAYMENT_METHOD_B2B_INVOICE:
                return 'B2B_INVOICE';
            case static::PAYMENT_METHOD_MASTERPASS:
                return 'MASTERPASS';
            case static::PAYMENT_METHOD_MOBILEPAY:
                return 'MOBILEPAY';
            case static::PAYMENT_METHOD_VIPPS:
                return 'VIPPS';
            default:
                return 'Unknown';
        }
    }

    /**
     * @param array $errors
     *
     * @return bool
     *
     * @throws AvardaException
     */
    private static function processErrors($errors)
    {
        if ($errors) {
            $json = json_decode($errors, true);
            if ($json) {
                if (is_array($json)) {
                    $errorsStrings = array_map(function ($error) {
                        return isset($error['ErrorMessage']) ? $error['ErrorMessage'] : 'Unknown error';
                    }, $json);
                    throw new AvardaException('API error: ' . implode(', ', $errorsStrings));
                }
                throw new AvardaException('API error: ' . $json);
            }
        }

        return true;
    }
}
