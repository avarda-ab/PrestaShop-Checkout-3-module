<?php
require_once 'checkout.php';

use PrestaShop\PrestaShop\Adapter\Cart\CartPresenter;
use PrestaShop\PrestaShop\Core\Crypto\Hashing;
use AvardaPayments\Api;
use AvardaPayments\AvardaException;
use AvardaPayments\Utils;

class avardapaymentsnotificationReceiverModuleFrontController extends ModuleFrontController 
{
  public function initContent()
  {
    parent::initContent();
  }

  function display() {
    return 'GET Request';
  }

  public function postProcess()
  {
    $json = file_get_contents('php://input');
		if(empty($json)) {
			exit;
		}
		$data = json_decode($json);
    //Logger::addLog('values in notificationreceiver' . var_dump($data), 1, null, null, null, true);
    $purchaseIdFromCallback = $data->purchaseId;
    if(!empty($purchaseIdFromCallback)) {
      $session = AvardaSession::getForPurchaseId($purchaseIdFromCallback);
      if(!$session) {
        exit("No session found, exiting");
      }
      if($session->status !== 'completed') {
        //handle validate here
        $checkoutController = new AvardaPaymentsCheckoutModuleFrontController();
        $paymentCompleted = $checkoutController->validatePayment($session);
        if(!$paymentCompleted) {
          //fallback, if something goes wrong on serverside
          exit('error when validating, this payment is not done, exiting');
        }
      }
    } 
  }
}