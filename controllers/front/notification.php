<?php

require_once __DIR__ . '/../../classes/AvardaPaymentsModuleFrontController.php';

class AvardaPaymentsNotificationModuleFrontController extends AvardaPaymentsModuleFrontController
{
    public function postProcess()
    {
        $data = @json_decode(file_get_contents('php://input'));
        if (!isset($data->purchaseId)) {
            die('Invalid data.');
        }

        $session = AvardaSession::getForPurchaseId($data->purchaseId);
        if (!Validate::isLoadedObject($session)) {
            die('Invalid session.');
        }

        sleep(20);

        $order = new Order((int)$session->id_order);
        $payment = $this->getPaymentInfo($session);

        if (!Validate::isLoadedObject($order) &&
            ($this->getPaymentStepName($payment) === 'Completed') &&
            $this->validateOrder($session, $payment) &&
            Validate::isLoadedObject($order = new Order((int)$this->module->currentOrder))) {
            $this->updateOrderState($session, $payment, $order);
        }

        exit;
    }
}
