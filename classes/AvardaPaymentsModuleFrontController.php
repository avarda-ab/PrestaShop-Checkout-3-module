<?php

use AvardaPayments\Api;
use AvardaPayments\Settings;

class AvardaPaymentsModuleFrontController extends ModuleFrontController
{
    /**
     * @var AvardaPayments
     */
    public $module;

    /**
     * @param bool $globalPayment
     *
     * @return Api
     */
    protected function getApi($globalPayment)
    {
        return $this->module->getApi($globalPayment);
    }

    /**
     * @param AvardaSession $session
     *
     * @return stdClass
     */
    protected function getPaymentInfo(AvardaSession $session)
    {
        return json_decode(json_encode($this->getApi($session->global)->getPaymentInfo($session->purchase_id)));
    }

    /**
     * @param Cart $cart
     *
     * @return bool
     */
    protected function checkIfCartIsValid(Cart $cart)
    {
        return ($cart->id_customer != 0) && ($cart->id_address_delivery != 0) && ($cart->id_address_invoice != 0);
    }

    /**
     * @param AvardaSession $session
     * @param Cart $cart
     *
     * @return bool
     */
    protected function addOrderMessage(AvardaSession $session, Cart $cart)
    {
        if (!$session->order_message) {
            return true;
        }

        $message = new Message();
        $message->message = $session->order_message;
        $message->id_cart = (int)$cart->id;
        $message->id_customer = (int)$cart->id_customer;

        return $message->save();
    }

    /**
     * @param AvardaSession $session
     * @param int $orderId
     *
     * @return bool
     */
    protected function updateSessionOrderId(AvardaSession $session, $orderId)
    {
        $session->id_order = (int)$orderId;

        return $session->save();
    }

    /**
     * @param AvardaSession $session
     * @param stdClass $payment
     *
     * @return bool
     */
    protected function validateOrder(AvardaSession $session, stdClass $payment)
    {
        $cart = new Cart((int)$session->id_cart);
        if (!Validate::isLoadedObject($cart) || !$this->checkIfCartIsValid($cart)) {
            return false;
        }

        $customer = new Customer($cart->id_customer);
        if (!Validate::isLoadedObject($customer)) {
            return false;
        }

        $this->addOrderMessage($session, $cart);

        if (!$this->module->validateOrder(
            (int)$cart->id,
            Configuration::get(AvardaPayments::OS_WAITING_FOR_PAYMENT),
            (float)$cart->getOrderTotal(true, Cart::BOTH),
            $this->module->displayName . ': ' . $payment->paymentMethods->selectedPayment->type,
            null,
            [],
            (int)$cart->id_currency,
            false,
            $customer->secure_key
        )) {
            return false;
        }

        if (!$this->updateSessionOrderId($session, $this->module->currentOrder)) {
            return false;
        }

        return true;
    }

    /**
     * @param stdClass $payment
     *
     * @return stdClass
     */
    protected function getPaymentModeInfo(stdClass $payment)
    {
        return $payment->mode === 'B2C' ? $payment->b2C : $payment->b2B;
    }

    /**
     * @param stdClass $payment
     *
     * @return string
     */
    protected function getPaymentStepName(stdClass $payment)
    {
        return (string)$this->getPaymentModeInfo($payment)->step->current;
    }

    /**
     * @return Settings
     */
    protected function getSettings()
    {
        return $this->module->getSettings();
    }

    /**
     * @param stdClass $payment
     *
     * @return int
     */
    protected function getOrderStateByPaymentInfo(stdClass $payment)
    {
        switch ($this->getPaymentStepName($payment)) {
            case 'Canceled':
                return (int)_PS_OS_CANCELED_;

            case 'WaitingForSwish':
            case 'WaitingForBankId':
            case 'AwaitingCreditApproval':
                return (int)Configuration::get(AvardaPayments::OS_WAITING_FOR_PAYMENT);

            case 'Completed':
                return $this->getSettings()->getCompletedStatus();
        }

        return (int)_PS_OS_ERROR_;
    }

    /**
     * @param string $orderReference
     * @param string $transactionId
     *
     * @return bool
     */
    protected function setTransactionId($orderReference, $transactionId)
    {
        $orderPayments = (new PrestaShopCollection('OrderPayment'))
            ->where('order_reference', '=', $orderReference);

        $orderPayment = $orderPayments->getFirst();
        if (empty($orderPayment) === true) {
            return false;
        }

        $payment = new OrderPayment($orderPayment->id);
        $payment->transaction_id = $transactionId;

        return $payment->save();
    }

    /**
     * @param AvardaSession $session
     * @param string $status
     *
     * @return bool
     */
    protected function updateSessionStatus(AvardaSession $session, $status)
    {
        $session->status = (string)$status;

        return $session->save();
    }

    /**
     * @return OrderManager
     */
    protected function getOrderManager()
    {
        return $this->module->getOrderManager();
    }

    /**
     * @param AvardaSession $session
     * @param stdClass $payment
     * @param Order $order
     */
    protected function updateOrderState(AvardaSession $session, stdClass $payment, Order $order)
    {
        $state = $this->getOrderStateByPaymentInfo($payment);
        if ($state === (int)$order->getCurrentState()) {
            return;
        }

        $orderHistory = new OrderHistory();
        $orderHistory->id_order = $order->id;
        $orderHistory->changeIdOrderState($state, $order->id);
        $orderHistory->addWithemail();

        $settings = $this->getSettings();

        if ($state === $settings->getCompletedStatus()) {
            $this->updateSessionStatus($session, 'completed');
            $this->setTransactionId($order->reference, $session->purchase_id);
            $this->getApi($session->global)->putExtraIdentifiers($order->reference, $session->purchase_id);
            $this->getOrderManager()->authorize($order, (float)$payment->totalPrice);

            if ($settings->getDeliveryStatus() === $settings->getCompletedStatus()) {
                $this->getOrderManager()->delivery($order);
            }
        } elseif ($state === (int)_PS_OS_ERROR_) {
            $this->updateSessionStatus($session, 'error');
        } elseif ($state === (int)_PS_OS_CANCELED_) {
            $this->updateSessionStatus($session, 'canceled');
        }
    }

    /**
     * @param Order $order
     * @param stdClass $payment
     *
     * @return string
     */
    protected function getOrderConfirmationUrl(Order $order, stdClass $payment)
    {
        return $this->context->link->getPageLink(
            'order-confirmation',
            true,
            $this->context->language->id,
            [
                'id_cart' => $order->id_cart,
                'id_module' => $this->module->id,
                'id_order' => $order->id,
                'key' => $order->secure_key,
                // 'status' => strtolower($this->getPaymentStepName($payment)),
            ]
        );
    }
}
