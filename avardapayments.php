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

require_once __DIR__ . '/app-translation.php';
require_once __DIR__ . '/classes/settings.php';
require_once __DIR__ . '/classes/fetch.php';
require_once __DIR__ . '/classes/api.php';
require_once __DIR__ . '/classes/utils.php';
require_once __DIR__ . '/classes/avarda-exception.php';
require_once __DIR__ . '/classes/order-manager.php';

require_once __DIR__ . '/model/session.php';
require_once __DIR__ . '/model/transaction.php';

use PrestaShop\PrestaShop\Core\Payment\PaymentOption;

class AvardaPayments extends PaymentModule
{
    /** @var \AvardaPayments\Settings */
    private $settings = null;

    public function __construct()
    {
        $this->name = 'avardapayments';
        $this->tab = 'payments_gateways';
        $this->version = '1.1.0';
        $this->author = 'DataKick, Loiki';
        $this->need_instance = 0;
        $this->bootstrap = true;
        parent::__construct();
        $this->displayName = $this->l('Avarda');
        $this->description = $this->l('Avarda payment gateway');
        $this->moduleNameTranslatable = $this->displayName;
        $this->moduleDescriptionTranslatable = $this->l('Payment option');
        $this->ps_versions_compliancy = array('min' => '1.7', 'max' => _PS_VERSION_);
        $this->controllers = ['checkout'];
    }


    /**
     * @param bool $createTables
     * @return bool
     * @throws PrestaShopDatabaseException
     * @throws PrestaShopException
     */
    public function install($createTables = true)
    {
        return (
            parent::install() &&
            $this->installDb($createTables) &&
            $this->installTab() &&
            $this->registerHooks() &&
            $this->getSettings()->init()
        );
    }

    /**
     * @param bool $dropTables
     * @return bool
     * @throws PrestaShopDatabaseException
     * @throws PrestaShopException
     */
    public function uninstall($dropTables = true)
    {
        return (
            $this->getSettings()->remove() &&
            $this->removeTab() &&
            $this->uninstallDb($dropTables) &&
            parent::uninstall()
        );
    }

    /**
     * @return bool
     * @throws PrestaShopDatabaseException
     * @throws PrestaShopException
     */
    public function reset()
    {
        return (
            $this->uninstall(false) &&
            $this->install(false)
        );
    }

    /**
     * @param $create
     * @return bool
     */
    private function installDb($create)
    {
        if (!$create) {
            return true;
        }
        return $this->executeSqlScript('install');
    }

    /**
     * @param $drop
     * @return bool
     */
    private function uninstallDb($drop)
    {
        if (!$drop) {
            return true;
        }
        return $this->executeSqlScript('uninstall', false);
    }

    /**
     * @param $script
     * @param bool $check
     * @return bool
     */
    public function executeSqlScript($script, $check = true)
    {
        $file = dirname(__FILE__) . '/sql/' . $script . '.sql';
        if (!file_exists($file)) {
            return false;
        }
        $sql = file_get_contents($file);
        if (!$sql) {
            return false;
        }
        $sql = str_replace(['PREFIX_', 'ENGINE_TYPE', 'CHARSET_TYPE'], [_DB_PREFIX_, _MYSQL_ENGINE_, 'utf8'], $sql);
        $sql = preg_split("/;\s*[\r\n]+/", $sql);
        foreach ($sql as $statement) {
            $stmt = trim($statement);
            if ($stmt) {
                try {
                    if (!Db::getInstance()->execute($stmt)) {
                        PrestaShopLogger::addLog("avarda: migration script $script: $stmt: error");
                        if ($check) {
                            return false;
                        }
                    }
                } catch (\Exception $e) {
                    PrestaShopLogger::addLog("avarda: migration script $script: $stmt: exception");
                    if ($check) {
                        return false;
                    }
                }
            }
        }
        return true;
    }

    /**
     * @return bool
     */
    public function registerHooks()
    {
        return (
            $this->registerHook('paymentOptions') &&
            $this->registerHook('actionOrderStatusUpdate') &&
            $this->registerHook('displayAdminOrder') &&
            $this->registerHook('actionProductCancel')
        );
    }


    /**
     * @return int
     * @throws PrestaShopDatabaseException
     * @throws PrestaShopException
     */
    private function installTab()
    {
        $tab = new Tab();
        $tab->active = 1;
        $tab->class_name = 'AdminAvardaPaymentsBackend';
        $tab->module = $this->name;
        $tab->id_parent = $this->getTabParent();
        $tab->name = array();
        foreach (Language::getLanguages(true) as $lang) {
            $tab->name[$lang['id_lang']] = 'Avarda payments';
        }
        return $tab->add();
    }

    /**
     * @return bool
     * @throws PrestaShopDatabaseException
     * @throws PrestaShopException
     */
    private function removeTab() {
        $tabId = Tab::getIdFromClassName('AdminAvardaPaymentsBackend');
        if ($tabId) {
            $tab = new Tab($tabId);
            return $tab->delete();
        }
        return true;
    }

    /**
     * @return int
     */
    private function getTabParent() {
        $parent = Tab::getIdFromClassName('AdminParentPayment');
        if ($parent !== false) {
            return $parent;
        }
        return 0;
    }

    /**
     * @throws PrestaShopException
     */
    public function getContent()
    {
        Tools::redirectAdmin($this->context->link->getAdminLink('AdminAvardaPaymentsBackend').'#/settings');
    }

    /**
     * @return \AvardaPayments\Settings
     */
    public function getSettings()
    {
        if (!$this->settings) {
            $this->settings = new \AvardaPayments\Settings();
        }
        return $this->settings;
    }

    /**
     * @return array|null
     */
    public function hookPaymentOptions()
    {
        if (!$this->active) {
            return null;
        }

        if (!$this->getSettings()->hasCredentials()) {
            return null;
        }

        $settings = $this->getSettings();

        // If settings has not module information.
        if (!$settings->getModuleInfo()) {
            $moduleName = "Avarda";
            $moduleDescription = "Payment option";
        } else {
            $moduleInfo = $settings->getModuleInfo();

            $moduleName = $moduleInfo['moduleName'];
            $moduleDescription = $moduleInfo['moduleDescription'];
        }

        // In case of default name, use variables that might have translations
        if($moduleName === 'Avarda') {
            $moduleName = $this->moduleNameTranslatable;
        }

        if($moduleDescription === 'Payment option') {
            $moduleDescription = $this->moduleDescriptionTranslatable;
        }  

        // Looks weird but we need to parse new line from json to enter to make css pre-line work.
        $parsedModuleDescription = str_replace('\n', '
        ', $moduleDescription);
        $logoDir =  _PS_MODULE_DIR_ . $this->name . '/uploads';
				foreach (new DirectoryIterator($logoDir) as $file) {
					if($file->isDot()) continue;
					$logo = $file->getFilename();
				}
	
				$logoUrl = _MODULE_DIR_ . $this->name . '/uploads/' . $logo;
        $this->smarty->assign('logoUrl', $logoUrl);
        $this->smarty->assign('description', $parsedModuleDescription);
        $option = new PaymentOption();
        $option->setCallToActionText($moduleName)
            ->setAction($this->context->link->getModuleLink($this->name, 'checkout', [], true))
            ->setAdditionalInformation($this->display(__FILE__, 'views/templates/hook/payment-option.tpl'));

        return [
            $option
        ];
    }

    /**
     * @param $params
     * @throws PrestaShopDatabaseException
     * @throws PrestaShopException
     * @throws \AvardaPayments\AvardaException
     */
    public function hookActionOrderStatusUpdate($params)
    {
        if (! isset($params['newOrderStatus'])) {
            return;
        }
        if (! isset($params['id_order'])) {
            return;
        }
        $orderState = $params['newOrderStatus'];
        if (! Validate::isLoadedObject($orderState)) {
            return;
        }
        if ($this->getSettings()->getDeliveryStatus() !== (int)$orderState->id) {
            return;
        }
        $id_order = (int)$params['id_order'];
        $order = new Order($id_order);
        if (Validate::isLoadedObject($order)) {
            $session = AvardaSession::getForOrder($order);
            if ($session) {
                $this->getOrderManager()->delivery($order);
            }
        }
    }

    /**
     * @param $params
     * @return string
     * @throws PrestaShopDatabaseException
     * @throws PrestaShopException
     * @throws \AvardaPayments\AvardaException
     */
    public function hookDisplayAdminOrder($params)
    {
        if (! isset($params['id_order'])) {
            return null;
        }
        $id_order = (int)$params['id_order'];
        $order = new Order($id_order);
        $manager = $this->getOrderManager();
        if ($manager->isAvardaOrder($order)) {
            $context = Context::getContext();
            $langId = (int)$context->language->id;
            $settings = $this->getSettings();
            $deliveryStatus = new OrderState($settings->getDeliveryStatus(), $langId);
            $params = [
                'avardaApiUrl' => $this->context->link->getAdminLink('AdminAvardaPaymentsBackend'),
                'avardaOrder' => $order,
                'avardaStatus' => $manager->getOrderStatus($order),
                'avardaRemaining' => $manager->getRemainingBalance($order),
                'avardaCaptured' => $manager->getCapturedBalance($order),
                'avardaReturned' => $manager->getReturnedBalance($order),
                'avardaCanceled' => $manager->getCanceledBalance($order),
                'avardaCanReturn' => $manager->canReturn($order),
                'avardaTransactions' => AvardaTransaction::getForOrder($order, true),
                'avardaDeliveryStatus' => $deliveryStatus->name,
            ];
            Context::getContext()->smarty->assign($params);
            return $this->display(__FILE__, 'admin-order.tpl');
        }
    }

    /**
     * @param array $params
     * @throws \AvardaPayments\AvardaException
     */
    public function hookActionProductCancel($params)
    {
        static $returned = false;
        if ($returned) {
            return;
        }
        $return = Tools::getValue('avardaReturn');
        if ($return) {
            $returned = true;
            /** @var Order $order */
            $returnList = Tools::getValue('cancelQuantity');
            if ($returnList) {
                // get product list
                $order = $params['order'];
                $products = $order->getProducts();

                // get return list
                $returnList = array_map('intval', $returnList);
                $customizationList = Tools::getValue('id_customization');
                if ($customizationList) {
                    $customizationList = array_map('intval', $customizationList);

                    $customizationQtyList = Tools::getValue('cancelCustomizationQuantity');
                    if ($customizationQtyList) {
                        $customizationQtyList = array_map('intval', $customizationQtyList);
                    }

                    foreach ($customizationList as $key => $id_order_detail) {
                        $returnList[(int) $id_order_detail] = $id_order_detail;
                        if (isset($customizationQtyList[$key])) {
                            $returnList[(int) $id_order_detail] += $customizationQtyList[$key];
                        }
                    }
                }

                // create payload
                $manager = $this->getOrderManager();

                $items = [];
                foreach ($returnList as $id => $qty) {
                    $orderDetail = $products[$id];
                    $amount = $orderDetail['unit_price_tax_incl'] * $qty;
                    $items[] = [
                        'Amount' => \AvardaPayments\Utils::roundPrice($amount),
                        'Description' => \AvardaPayments\Utils::maxChars(sprintf($this->l('Return: %s x %s'), $qty, $orderDetail['product_name']), 35)
                    ];
                }

                $manager->returnItems($order, $items);
            }
        }
    }

    /**
     * @return \AvardaPayments\OrderManager
     * @throws \AvardaPayments\AvardaException
     */
    public function getOrderManager()
    {
        return new \AvardaPayments\OrderManager($this->getApi());
    }

    /**
     * @return \AvardaPayments\Api
     * @throws \AvardaPayments\AvardaException
     */
    public function getApi()
    {
        $settings = $this->getSettings();
        $credentials = $settings->getCredentials();
        return new \AvardaPayments\Api($settings->getMode(), $credentials['code'], $credentials['password']);
    }

    /**
     * @return array
     */
    public function getTranslations()
    {
        $translations = new \AvardaPayments\AppTranslation($this);
        return $translations->getTranslations();
    }

    /**
     * @param $relative
     * @return string
     */
    public function getPath($relative) {
        $uri = rtrim($this->getPathUri(), '/');
        $rel = ltrim($relative, '/');
        return "$uri/$rel";
    }		
}

