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

use AvardaPayments\Api;

class AdminAvardaPaymentsBackendController extends ModuleAdminController
{
    /** @var AvardaPayments */
    public $module;

    public function __construct()
    {
        parent::__construct();
        $this->display = 'view';
        $this->bootstrap = false;
        $this->addCSS($this->module->getPath('views/css/back.css'));
        $this->addJs($this->module->getSettings()->getBackendAppUrl($this->module));
    }

    /**
     * @throws PrestaShopException
     * @throws SmartyException
     */
    public function display()
    {
        /*
        try {
            $order = new Order(11);
            echo "<pre>";
            $manager = $this->module->getOrderManager();
            $manager->getOrderItems($order);
            print_r([
                'authorized balance' => $manager->getAuthorizedBalance($order),
                'remaining balance' => $manager->getRemainingBalance($order),
                'status' => $manager->getOrderStatus($order),
            ]);
        } catch (Exception $e) {
            print_r("$e");
        }
        die("</pre>");
        */
        $settings = $this->module->getSettings();
        $this->display_footer = false;

        $this->context->smarty->assign([
            'help_link' => null,
            'title' => $this->l('Avarda payments'),
            'avarda' => [
                'apiUrl' => $this->context->link->getAdminLink('AdminAvardaPaymentsBackend'),
                'statuses' => $this->getOrderStates(),
                'settings' => $settings->get(),
                'translations' => $this->module->getTranslations(),
            ]
        ]);
        parent::display();
    }

    /**
     * @param string $tpl_name
     * @return Smarty_Internal_Template
     * @throws SmartyException
     */
    public function createTemplate($tpl_name)
    {
        if ($this->viewAccess() && $tpl_name === 'content.tpl') {
            $path = _PS_MODULE_DIR_ . $this->module->name . '/views/templates/admin/backend.tpl';
            return $this->context->smarty->createTemplate($path, $this->context->smarty);
        }
        return parent::createTemplate($tpl_name);
    }

    /**
     *
     */
    public function ajaxProcessCommand()
    {
        PrestaShopLogger::addLog('AdminAvardaPaymentsBackendController - ajaxProcessCommand() - command: ' . Tools::getValue('cmd'), 1, null, null, null, true);
        $error = null;
        $result = null;
        try {
            $result = $this->dispatchCommand(Tools::getValue('cmd'));
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
        $this->reply($error, $result);
    }
    
    /**
     * @param $cmd
     * @return mixed
     * @throws Exception
     */
    private function dispatchCommand($cmd)
    {
        $payload = isset($_POST['payload']) ? json_decode($_POST['payload'], true) : [];
        switch ($cmd) {
            case 'saveSettings':
                return $this->saveSettings($payload);
            case 'testCredentials':
                return $this->testCredentials($payload);
            case 'getSessions':
                return $this->getSessions($payload);
            case 'cancel':
                return $this->cancelPayment($payload);
            case 'capture':
                return $this->capturePayment($payload);
            case 'refund':
                return $this->refundPayment($payload);
            case 'return':
                return $this->returnPayment($payload);
            default:
                throw new Exception("Unknown command $cmd");
        }
    }


    /**
     * @param $settings
     * @return bool
     * @throws Exception
     */
    private function saveSettings($settings)
    {
        if (! isset($settings['settings'])) {
            throw new Exception("Failed to parse settings");
        }
        return !!$this->module->getSettings()->set($settings['settings']);
    }

    /**
     * @param $payload
     * @return bool
     */
    private function testCredentials($payload)
    {
        if (! isset($payload['mode'])) {
            return false;
        }
        if (! isset($payload['code'])) {
            return false;
        }
        if (! isset($payload['password'])) {
            return false;
        }
        $mode = $payload['mode'];
        $code = $payload['code'];
        $password = $payload['password'];
        if ($mode !== 'test' && $mode !== 'production') {
            return false;
        }
        try {
            $api = new Api($mode, $code, $password);
            $api->testApiCredentials($code, $password);
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * @param $data
     * @return array
     * @throws Exception
     */
    private function getSessions($data)
    {
        if (! isset($data['page'])) {
            throw new Exception('page not set');
        }
        AvardaSession::markExpired();
        $page = (int)$data['page'];
        $pageSize = 10;
        $offset = ($page-1) * $pageSize;
        $sql = (new DbQuery())
            ->select('s.*, c.firstname, c.lastname, o.reference')
            ->from('avarda_session', 's')
            ->leftJoin('customer', 'c', 'c.id_customer = s.id_customer')
            ->leftJoin('orders', 'o', 'o.id_order = s.id_order')
            ->orderBy('s.date_add DESC')
            ->limit($pageSize, $offset);
        $conn = Db::getInstance();
        $data = $conn->executeS($sql);
        $sessions = [];
        $link = $this->context->link;
        if ($data) {
            foreach ($data as $row) {
                $orderId = (int)$row['id_order'];
                $sessions[] = [
                    'code' => $row['purchase_id'],
                    'date' => $row['date_add'],
                    'mode' => $row['mode'],
                    'status' => $row['status'],
                    'orderId' => $orderId,
                    'orderReference' => $orderId ? $row['reference'] : '',
                    'orderUrl' => $orderId ? $link->getAdminLink('AdminOrders', true, [], ['vieworder' => 1, 'id_order' => $orderId]) : '',
                    'cartId' => (int)$row['id_cart'],
                    'cartUrl' => $link->getAdminLink('AdminCarts', true, [], ['viewcart' => 1, 'id_cart' => (int)$row['id_cart']]),
                    'customerId' => (int)$row['id_customer'],
                    'customerName' => $row['firstname'].' '.$row['lastname'],
                    'customerUrl' => $link->getAdminLink('AdminCustomers', true, [], ['viewcustomer' => 1, 'id_customer' => (int)$row['id_customer']]),
                ];
            }
        }
        $total = (int)$conn->getValue((new DbQuery())->select('COUNT(1)')->from('avarda_session'));
        return [
            'total' => $total,
            'sessions' => $sessions
        ];
    }

    /**
     * @param $data
     * @return bool
     * @throws Exception
     */
    private function cancelPayment($data)
    {
        if (!isset($data['orderId'])) {
            throw new Exception('order not set');
        }
        $order = new Order((int)$data['orderId']);
        if (! Validate::isLoadedObject($order)) {
            throw new Exception('Order not found');
        }
        $manager = $this->module->getOrderManager();
        if (! $manager->canCancel($order)) {
            throw new Exception("Can't cancel payment");
        }
        if (! $manager->cancelPayment($order)) {
            throw new Exception($manager->getLastError());
        }
        return true;
    }

    /**
     * @param $data
     * @return bool
     * @throws Exception
     */
    private function capturePayment($data)
    {
        if (!isset($data['orderId'])) {
            throw new Exception('order not set');
        }
        $order = new Order((int)$data['orderId']);
        if (! Validate::isLoadedObject($order)) {
            throw new Exception('Order not found');
        }
        $manager = $this->module->getOrderManager();
        if (! $manager->canDeliver($order)) {
            throw new Exception("Can't capture payment");
        }
        if (! $manager->delivery($order)) {
            throw new Exception($manager->getLastError());
        }
        return true;
    }

    /**
     * @param $data
     * @return bool
     * @throws Exception
     */
    private function refundPayment($data)
    {
        if (!isset($data['orderId'])) {
            throw new Exception('order not set');
        }
        if (!isset($data['amount'])) {
            throw new Exception('amount not set');
        }
        $order = new Order((int)$data['orderId']);
        if (! Validate::isLoadedObject($order)) {
            throw new Exception('Order not found');
        }
        $manager = $this->module->getOrderManager();
        if (! $manager->canRefund($order)) {
            throw new Exception("Can't refund payment");
        }
        if (! $manager->refund($order, $data['amount'])) {
            throw new Exception($manager->getLastError());
        }
        return true;
    }

    /**
     * @param $data
     * @return bool
     * @throws Exception
     */
    private function returnPayment($data)
    {
        if (!isset($data['orderId'])) {
            throw new Exception('order not set');
        }
        if (!isset($data['amount'])) {
            throw new Exception('amount not set');
        }
        if (!isset($data['reason'])) {
            throw new Exception('reason not set');
        }
        $order = new Order((int)$data['orderId']);
        if (! Validate::isLoadedObject($order)) {
            throw new Exception('Order not found');
        }
        $manager = $this->module->getOrderManager();
        if (! $manager->canReturn($order)) {
            throw new Exception("Can't return payment");
        }
        if (! $manager->returnItem($order, (double)$data['amount'], $data['reason'])) {
            throw new Exception($manager->getLastError());
        }
        return true;
    }

    /**
     * @param $error
     * @param $result
     */
    private function reply($error, $result)
    {
        if ($error) {
            echo json_encode(['success' => false, 'error' => $error]);
        } else {
            echo json_encode(['success' => true, 'result' => $result]);
        }
        die();
    }

    private function getOrderStates()
    {
        $idLang = (int)$this->context->language->id;
        $ret = [];
        foreach (OrderState::getOrderStates($idLang) as $row) {
            $id = (int)$row['id_order_state'];
            $name = $row['name'];
            $ret[$id] = $name;
        }
        return $ret;
    }

}
