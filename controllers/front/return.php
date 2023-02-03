<?php

require_once __DIR__ . '/../../classes/AvardaPaymentsModuleFrontController.php';

class AvardaPaymentsReturnModuleFrontController extends AvardaPaymentsModuleFrontController
{
    public function postProcess()
    {
        $session = new AvardaSession((int)Tools::getValue('id_session'));
        if (!Validate::isLoadedObject($session)) {
            die('Invalid session.');
        }

        $order = new Order((int)$session->id_order);
        if (!Validate::isLoadedObject($order)) {
            die('Invalid order.');
        }

        $payment = $this->getPaymentInfo($session);

        $this->updateOrderState($session, $payment, $order);
        Tools::redirect($this->getOrderConfirmationUrl($order, $payment));
    }
}
