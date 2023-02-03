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

require_once __DIR__ . '/model/customerLog.php';
require_once __DIR__ . '/model/log.php';
require_once __DIR__ . '/model/session.php';
require_once __DIR__ . '/model/transaction.php';

use AvardaPayments\Api as AvardaPaymentsApi;
use PrestaShop\PrestaShop\Adapter\StockManager;
use PrestaShop\PrestaShop\Core\Payment\PaymentOption;

class AvardaPayments extends PaymentModule
{
    /**
     * @var string
     */
    const LOG_NAME_VALIDATE_ORDER = 'validate_order';

    /**
     * @var string
     */
    const LOG_NAME_VALIDATE_ORDER_ORDERS = 'validate_order_orders';

    /**
     * @var string
     */
    const OS_WAITING_FOR_PAYMENT = 'AVARDA_PAYMENTS_OS_WAITING_FOR_PAYMENT';

    /**
     * @var \AvardaPayments\Settings
     */
    private $settings = null;

    public function __construct()
    {
        $this->name = 'avardapayments';
        $this->tab = 'payments_gateways';
        $this->version = '4.2.0';
        $this->author = 'DataKick, Loiki';
        $this->need_instance = 0;
        $this->bootstrap = true;
        parent::__construct();
        $this->displayName = $this->l('Avarda');
        $this->description = $this->l('Avarda payment gateway');
        $this->moduleNameTranslatable = $this->displayName;
        $this->moduleDescriptionTranslatable = $this->l('Payment option');
        $this->ps_versions_compliancy = ['min' => '1.7', 'max' => _PS_VERSION_];
        $this->controllers = ['checkout'];

        Db::getInstance()->execute('
            CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'avarda_log` (
                `id_avarda_log` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
                `name` VARCHAR(255) NOT NULL,
                `data` TEXT NOT NULL,
                `date_add` DATETIME NOT NULL,
                PRIMARY KEY (`id_avarda_log`)
            ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=UTF8;
        ');

        Db::getInstance()->execute('
            CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'avarda_customer_log` (
                `id_avarda_customer_log` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
                `id_customer` INT(10) UNSIGNED,
                `data` TEXT NOT NULL,
                `date_add` DATETIME NOT NULL,
                PRIMARY KEY (`id_avarda_customer_log`)
            ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=UTF8;
        ');

        if (!$this->isRegisteredInHook('actionObjectCustomerAddAfter')) {
            $this->registerHook('actionObjectCustomerAddAfter');
        }

        if (!$this->isRegisteredInHook('actionObjectCustomerUpdateAfter')) {
            $this->registerHook('actionObjectCustomerUpdateAfter');
        }
    }

    /**
     * @return bool
     */
    public function createOrderState()
    {
        $stateId = Configuration::getGlobalValue(self::OS_WAITING_FOR_PAYMENT);
        if ($stateId === false) {
            if (!Db::getInstance()->insert('order_state', [
                'module_name' => $this->name,
                'color' => '#4169E1',
                'unremovable' => 1,
            ])) {
                return false;
            }

            Configuration::updateGlobalValue(
                AvardaPayments::OS_WAITING_FOR_PAYMENT,
                ($stateId = (int)Db::getInstance()->Insert_ID())
            );
        }

        foreach (Language::getLanguages(false, false, true) as $languageId) {
            $query = (new DbQuery())
                ->select('id_order_state')
                ->from('order_state_lang')
                ->where('id_order_state = ' . $stateId . ' AND id_lang = ' . (int)$languageId);

            if (!Db::getInstance()->getValue($query->build()) &&
                !Db::getInstance()->insert('order_state_lang', [
                    'id_order_state' => $stateId,
                    'id_lang' => (int)$languageId,
                    'name' => pSQL('Waiting for Avarda payment'),
                    'template' => 'payment',
                ])) {
                return false;
            }
        }

        if (file_exists($iconToPaste = _PS_ORDER_STATE_IMG_DIR_ . $stateId . ($iconExtension = '.gif')) &&
            !is_writable($iconToPaste)) {
            return false;
        }

        return copy(
            $this->local_path . 'views/img/order_state_icons/waiting' . $iconExtension,
            $iconToPaste
        );
    }

    /**
     * @param bool $createTables
     *
     * @return bool
     *
     * @throws PrestaShopDatabaseException
     * @throws PrestaShopException
     */
    public function install($createTables = true)
    {
        return
            parent::install() &&
            $this->installDb($createTables) &&
            $this->installTab() &&
            $this->createOrderState() &&
            $this->registerHooks() &&
            $this->getSettings()->init()
        ;
    }

    /**
     * @param bool $dropTables
     *
     * @return bool
     *
     * @throws PrestaShopDatabaseException
     * @throws PrestaShopException
     */
    public function uninstall($dropTables = true)
    {
        return
            $this->getSettings()->remove() &&
            $this->removeTab() &&
            $this->uninstallDb($dropTables) &&
            parent::uninstall()
        ;
    }

    /**
     * @return bool
     *
     * @throws PrestaShopDatabaseException
     * @throws PrestaShopException
     */
    public function reset()
    {
        return
            $this->uninstall(false) &&
            $this->install(false)
        ;
    }

    /**
     * @param $create
     *
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
     *
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
     *
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
        return
            $this->registerHook('paymentOptions') &&
            $this->registerHook('actionOrderStatusUpdate') &&
            $this->registerHook('orderConfirmation') &&
            $this->registerHook('displayAdminOrder') &&
            $this->registerHook('actionProductCancel') &&
            $this->registerHook('actionAdminControllerSetMedia')
        ;
    }

    /**
     * @return int
     *
     * @throws PrestaShopDatabaseException
     * @throws PrestaShopException
     */
    private function installTab()
    {
        $tab = new Tab();
        $tab->active = 1;
        $tab->class_name = 'AdminAvardaPaymentsBackend';
        $tab->module = $this->name;
        $tab->id_parent = -1;
        $tab->name = [];
        foreach (Language::getLanguages(true) as $lang) {
            $tab->name[$lang['id_lang']] = 'Avarda payments';
        }

        return $tab->add();
    }

    /**
     * @return bool
     *
     * @throws PrestaShopDatabaseException
     * @throws PrestaShopException
     */
    private function removeTab()
    {
        $tabId = Tab::getIdFromClassName('AdminAvardaPaymentsBackend');
        if ($tabId) {
            $tab = new Tab($tabId);

            return $tab->delete();
        }

        return true;
    }

    /**
     * @param array $errors
     *
     * @return bool
     */
    protected function validateGeneralSettings(array &$errors)
    {
        $completedStatus = (int)Tools::getValue('completed_status');
        if (!$completedStatus) {
            $errors[] = $this->l('Invalid completed status.');
        }

        $deliveryStatus = (int)Tools::getValue('delivery_status');
        if (!$deliveryStatus) {
            $errors[] = $this->l('Invalid delivery status.');
        }

        if (Tools::getValue('user_logo')) {
            if (!$_FILES['user_logo']['name']) {
                $errors[] = $this->l('Invalid user logo name.');
            } elseif (!$_FILES['user_logo']['type']) {
                $errors[] = $this->l('Invalid user logo type.');
            } elseif (!$_FILES['user_logo']['tmp_name']) {
                $errors[] = $this->l('Invalid user logo temporary name.');
            } elseif (!$_FILES['user_logo']['size']) {
                $errors[] = $this->l('Invalid user logo size.');
            } else {
                $extension = pathinfo($_FILES['user_logo']['name'], PATHINFO_EXTENSION);
                if (!in_array(strtolower($extension), ['jpg', 'jpeg', 'png'])) {
                    $errors[] = $this->l('Invalid user logo extension.');
                }
            }
        }

        return !count($errors);
    }

    /**
     * @return bool
     */
    protected function uploadUserLogo()
    {
        if (!is_dir($uploadDir = _PS_MODULE_DIR_ . $this->name . '/uploads')) {
            mkdir($uploadDir, 0755);
        }

        $settings = $this->getSettings();

        if (($filename = $settings->get(['user_logo_filename'])) &&
            file_exists($path = $uploadDir . '/' . $filename)) {
            @unlink($path);
        }

        $filename = 'logo.' . pathinfo($_FILES['user_logo']['name'], PATHINFO_EXTENSION);
        if (file_exists($path = $uploadDir . '/' . $filename)) {
            @unlink($path);
        }

        $data = $settings->get();
        $data['user_logo_filename'] = $filename;

        if (!$settings->set($data)) {
            return false;
        }

        return move_uploaded_file($_FILES['user_logo']['tmp_name'], $path);
    }

    /**
     * @param array $extraParams
     *
     * @return string
     */
    protected function getAdminLink(array $extraParams = [])
    {
        $params = [
            'configure' => $this->name
        ];

        if ($extraParams) {
            $params = array_merge($params, $extraParams);
        }

        return $this->context->link->getAdminLink('AdminModules', false, [], $params);
    }

    /**
     * @return string
     */
    protected function getAdminToken()
    {
        return Tools::getAdminTokenLite('AdminModules');
    }

    /**
     * @param array $errors
     */
    protected function saveGeneralSettings(array &$errors)
    {
        if (!$this->validateGeneralSettings($errors)) {
            return;
        }

        $settings = $this->getSettings();

        $data = $settings->get();
        $data['mode'] = Tools::getValue('test_mode') ? 'test' : 'production';
        $data['completedStatus'] = (int)Tools::getValue('completed_status');
        $data['deliveryStatus'] = (int)Tools::getValue('delivery_status');
        $data['useOnePage'] = (int)((bool)Tools::getValue('use_one_page'));

        if (!$settings->set($data)) {
            $errors[] = $this->l('Failed to save general settings.');
        } elseif (Tools::getValue('user_logo') && !$this->uploadUserLogo()) {
            $errors[] = $this->l('Failed to upload user logo.');
        } else {
            Tools::redirectAdmin($this->getAdminLink([
                'conf' => 4,
                'token' => $this->getAdminToken(),
            ]));
        }
    }

    /**
     * @param mixed $credentials
     *
     * @return bool
     */
    protected function validateApiCredentialsDataStructure($credentials)
    {
        return is_array($credentials) &&
            isset($credentials['test']) &&
            isset($credentials['test']['code']) &&
            isset($credentials['test']['password']) &&
            isset($credentials['production']) &&
            isset($credentials['production']['code']) &&
            isset($credentials['production']['password']);
    }

    /**
     * @param string $mode
     * @param string $code
     * @param string $password
     *
     * @return bool
     */
    protected function checkApiCredentials($mode, $code, $password)
    {
        try {
            (new AvardaPaymentsApi($mode, $code, $password))->testApiCredentials($code, $password);
        } catch (Exception $e) {
            return false;
        }

        return true;
    }

    /**
     * @param array $credentials
     * @param array $errors
     */
    protected function validatePaymentApiTestCredentials(array $credentials, array &$errors)
    {
        $code = trim((string)$credentials['test']['code']);
        $password = trim((string)$credentials['test']['password']);

        if ($code) {
            if (!$password) {
                $errors[] = $this->l('Invalid payment API password (test).');
            } elseif (!$this->checkApiCredentials('test', $code, $password)) {
                $errors[] = $this->l('Invalid payment API credentials (test).');
            }
        } else {
            if ($password) {
                $errors[] = $this->l('Invalid payment API username (test).');
            }
        }
    }

    /**
     * @param array $credentials
     * @param array $errors
     */
    protected function validatePaymentApiProductionCredentials(array $credentials, array &$errors)
    {
        $code = trim((string)$credentials['production']['code']);
        $password = trim((string)$credentials['production']['password']);

        if ($code) {
            if (!$password) {
                $errors[] = $this->l('Invalid payment API password (production).');
            } elseif (!$this->checkApiCredentials('production', $code, $password)) {
                $errors[] = $this->l('Invalid payment API credentials (production).');
            }
        } else {
            if ($password) {
                $errors[] = $this->l('Invalid payment API username (production).');
            }
        }
    }

    /**
     * @param array $errors
     *
     * @return bool
     */
    protected function validatePaymentApiCredentials(array &$errors)
    {
        $credentials = Tools::getValue('credentials');

        if (!$this->validateApiCredentialsDataStructure($credentials)) {
            $errors[] = $this->l('Invalid payment API credentials data.');
        } else {
            $this->validatePaymentApiTestCredentials($credentials, $errors);
            $this->validatePaymentApiProductionCredentials($credentials, $errors);
        }

        return !count($errors);
    }

    /**
     * @param array $errors
     */
    protected function savePaymentApiCredentials(array &$errors)
    {
        if (!$this->validatePaymentApiCredentials($errors)) {
            return;
        }

        $settings = $this->getSettings();
        $credentials = Tools::getValue('credentials');

        $data = $settings->get();
        $data['credentials']['test']['code'] = trim($credentials['test']['code']);
        $data['credentials']['test']['password'] = trim($credentials['test']['password']);
        $data['credentials']['production']['code'] = trim($credentials['production']['code']);
        $data['credentials']['production']['password'] = trim($credentials['production']['password']);

        if (!$settings->set($data)) {
            $errors[] = $this->l('Failed to save payment API credentials.');
        } else {
            Tools::redirectAdmin($this->getAdminLink([
                'conf' => 4,
                'token' => $this->getAdminToken(),
            ]));
        }
    }

    /**
     * @param array $credentials
     * @param array $errors
     */
    protected function validateGlobalPaymentApiTestCredentials(array $credentials, array &$errors)
    {
        $code = trim((string)$credentials['test']['code']);
        $password = trim((string)$credentials['test']['password']);

        if ($code) {
            if (!$password) {
                $errors[] = $this->l('Invalid global payment API password (test).');
            } elseif (!$this->checkApiCredentials('test', $code, $password)) {
                $errors[] = $this->l('Invalid global payment API credentials (test).');
            }
        } else {
            if ($password) {
                $errors[] = $this->l('Invalid global payment API username (test).');
            }
        }
    }

    /**
     * @param array $credentials
     * @param array $errors
     */
    protected function validateGlobalPaymentApiProductionCredentials(array $credentials, array &$errors)
    {
        $code = trim((string)$credentials['production']['code']);
        $password = trim((string)$credentials['production']['password']);

        if ($code) {
            if (!$password) {
                $errors[] = $this->l('Invalid global payment API password (production).');
            } elseif (!$this->checkApiCredentials('production', $code, $password)) {
                $errors[] = $this->l('Invalid global payment API credentials (production).');
            }
        } else {
            if ($password) {
                $errors[] = $this->l('Invalid global payment API username (production).');
            }
        }
    }

    /**
     * @param array $errors
     *
     * @return bool
     */
    protected function validateGlobalPaymentApiCredentials(array &$errors)
    {
        $credentials = Tools::getValue('credentials_global');

        if (!$this->validateApiCredentialsDataStructure($credentials)) {
            $errors[] = $this->l('Invalid global payment API credentials data.');
        } else {
            $this->validateGlobalPaymentApiTestCredentials($credentials, $errors);
            $this->validateGlobalPaymentApiProductionCredentials($credentials, $errors);
        }

        return !count($errors);
    }

    /**
     * @param array $errors
     */
    protected function saveGlobalPaymentApiCredentials(array &$errors)
    {
        if (!$this->validateGlobalPaymentApiCredentials($errors)) {
            return;
        }

        $settings = $this->getSettings();
        $credentials = Tools::getValue('credentials_global');

        $data = $settings->get();
        $data['global']['credentials']['test']['code'] = trim($credentials['test']['code']);
        $data['global']['credentials']['test']['password'] = trim($credentials['test']['password']);
        $data['global']['credentials']['production']['code'] = trim($credentials['production']['code']);
        $data['global']['credentials']['production']['password'] = trim($credentials['production']['password']);

        if (!$settings->set($data)) {
            $errors[] = $this->l('Failed to save global payment API credentials.');
        } else {
            Tools::redirectAdmin($this->getAdminLink([
                'conf' => 4,
                'token' => $this->getAdminToken(),
            ]));
        }
    }

    /**
     * @param array $errors
     *
     * @return bool
     */
    protected function validateGlobalPaymentBindings(array &$errors)
    {
        if (!is_array(Tools::getValue('bindings_global'))) {
            $errors[] = $this->l('Invalid global payment bindings data.');
        }

        return !count($errors);
    }

    /**
     * @param array $errors
     */
    protected function saveGlobalPaymentBindings(array &$errors)
    {
        if (!$this->validateGlobalPaymentBindings($errors)) {
            return;
        }

        $settings = $this->getSettings();

        $data = $settings->get();
        $data['global']['bindings'] = json_encode(Tools::getValue('bindings_global'));

        if (!$settings->set($data)) {
            $errors[] = $this->l('Failed to save global payment bindings.');
        } else {
            Tools::redirectAdmin($this->getAdminLink([
                'conf' => 4,
                'token' => $this->getAdminToken(),
            ]));
        }
    }

    /**
     * @return string|null
     */
    protected function getUserLogoUrl()
    {
        if (($filename = $this->getSettings()->get(['user_logo_filename'])) &&
            file_exists($this->local_path . 'uploads/' . $filename)) {
            return $this->_path . 'uploads/' . $filename;
        }

        return null;
    }

    /**
     * @return Controller|AdminController
     */
    protected function getController()
    {
        return $this->context->controller;
    }

    /**
     * @param string $submitAction
     * @param array $fieldsValues
     * @param array $settings
     *
     * @return string
     */
    protected function displaySettingsForm($submitAction, array $fieldsValues, array $settings)
    {
        $helper = new HelperForm();
        $helper->show_toolbar = false;
        $helper->default_form_language = (int)Configuration::get('PS_LANG_DEFAULT');
        $helper->allow_employee_form_lang = (int)Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG');
        $helper->submit_action = $submitAction;
        $helper->currentIndex = $this->getAdminLink();
        $helper->token = $this->getAdminToken();

        $helper->tpl_vars = [
            'fields_value' => $fieldsValues,
            'languages' => $this->getController()->getLanguages(),
            'id_language' => $this->context->language->id,
        ];

        return $helper->generateForm(['form' => ['form' => $settings]]);
    }

    /**
     * @return string
     */
    protected function displayGeneralSettingsForm()
    {
        $settings = $this->getSettings();

        $input = [
            [
                'type' => 'switch',
                'label' => $this->l('Test Mode'),
                'name' => 'test_mode',
                'is_bool' => true,
                'values' => [
                    ['value' => 1],
                    ['value' => 0],
                ],
                'desc' => $this->l('Test mode allows you to verify Avarda integration against staging environment'),
            ],
            [
                'type' => 'select',
                'label' => $this->l('Completed Status'),
                'name' => 'completed_status',
                'options' => [
                    'query' => OrderState::getOrderStates((int)$this->context->language->id),
                    'id' => 'id_order_state',
                    'name' => 'name',
                ],
                'desc' => $this->l('Order status when payment has been successfully completed'),
            ],
            [
                'type' => 'select',
                'label' => $this->l('Delivery Status'),
                'name' => 'delivery_status',
                'options' => [
                    'query' => OrderState::getOrderStates((int)$this->context->language->id),
                    'id' => 'id_order_state',
                    'name' => 'name',
                ],
                'desc' => $this->l('When order transition to this status, avarda Purchase Order will be created'),
            ],
            [
                'type' => 'switch',
                'label' => $this->l('One Page Checkout'),
                'name' => 'use_one_page',
                'is_bool' => true,
                'values' => [
                    ['value' => 1],
                    ['value' => 0],
                ],
                'desc' => $this->l('The module displays its own checkout page if enabled'),
            ],
        ];

        if ($url = $this->getUserLogoUrl()) {
            $input[] = [
                'type' => 'html',
                'name' => 'user_logo_preview',
                'html_content' => '
                    <div style="display: inline-block; border: 1px solid #eee; padding: 1px;">
                        <img src="' . $url . '" alt="' . $this->l('Logo') . '" />
                    </div>
                ',
            ];
        }

        $input[] = [
            'type' => 'file',
            'label' => $this->l('Logo'),
            'name' => 'user_logo',
            'desc' => $this->l('Shown for customers when one page checkout is disabled'),
        ];

        return $this->displaySettingsForm(
            'submit_general_settings',
            [
                'test_mode' => Tools::getValue('test_mode', $settings->getMode() === 'test'),
                'completed_status' => Tools::getValue('completed_status', $settings->getCompletedStatus()),
                'delivery_status' => Tools::getValue('delivery_status', $settings->getDeliveryStatus()),
                'use_one_page' => Tools::getValue('use_one_page', $settings->getUseOnePage()),
            ],
            [
                'legend' => [
                    'title' => $this->l('General Settings'),
                    'icon' => 'icon-cogs'
                ],
                'input' => $input,
                'submit' => [
                    'title' => $this->l('Save'),
                ],
            ]
        );
    }

    /**
     * @return string
     */
    protected function displayPaymentApiCredentialsForm()
    {
        $settings = $this->getSettings();
        $credentials = Tools::getValue('credentials');

        return $this->displaySettingsForm(
            'submit_payment_api_credentials',
            [
                'credentials[test][code]' => $credentials ? $credentials['test']['code'] :
                    $settings->get(['credentials', 'test', 'code']),
                'credentials[test][password]' => $credentials ? $credentials['test']['password'] :
                    $settings->get(['credentials', 'test', 'password']),
                'credentials[production][code]' => $credentials ? $credentials['production']['code'] :
                    $settings->get(['credentials', 'production', 'code']),
                'credentials[production][password]' => $credentials ? $credentials['production']['password'] :
                    $settings->get(['credentials', 'production', 'password']),
            ],
            [
                'legend' => [
                    'title' => $this->l('API Credentials'),
                    'icon' => 'icon-cogs'
                ],
                'input' => [
                    [
                        'type' => 'text',
                        'label' => $this->l('Username') . ' (' . $this->l('production') . ')',
                        'name' => 'credentials[production][code]',
                        'class' => 'fixed-width-xxl',
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('Password') . ' (' . $this->l('production') . ')',
                        'name' => 'credentials[production][password]',
                        'class' => 'fixed-width-xxl',
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('Username') . ' (' . $this->l('test') . ')',
                        'name' => 'credentials[test][code]',
                        'class' => 'fixed-width-xxl',
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('Password') . ' (' . $this->l('test') . ')',
                        'name' => 'credentials[test][password]',
                        'class' => 'fixed-width-xxl',
                    ],
                ],
                'submit' => [
                    'title' => $this->l('Save'),
                ],
            ]
        );
    }

    /**
     * @return string
     */
    protected function displayGlobalPaymentApiCredentialsForm()
    {
        $settings = $this->getSettings();
        $credentials = Tools::getValue('credentials_global');

        return $this->displaySettingsForm(
            'submit_global_payment_api_credentials',
            [
                'credentials_global[test][code]' => $credentials ? $credentials['test']['code'] :
                    $settings->get(['global', 'credentials', 'test', 'code']),
                'credentials_global[test][password]' => $credentials ? $credentials['test']['password'] :
                    $settings->get(['global', 'credentials', 'test', 'password']),
                'credentials_global[production][code]' => $credentials ? $credentials['production']['code'] :
                    $settings->get(['global', 'credentials', 'production', 'code']),
                'credentials_global[production][password]' => $credentials ? $credentials['production']['password'] :
                    $settings->get(['global', 'credentials', 'production', 'password']),
            ],
            [
                'legend' => [
                    'title' => $this->l('API Credentials (Global Payment)'),
                    'icon' => 'icon-cogs'
                ],
                'input' => [
                    [
                        'type' => 'text',
                        'label' => $this->l('Username') . ' (' . $this->l('production') . ')',
                        'name' => 'credentials_global[production][code]',
                        'class' => 'fixed-width-xxl',
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('Password') . ' (' . $this->l('production') . ')',
                        'name' => 'credentials_global[production][password]',
                        'class' => 'fixed-width-xxl',
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('Username') . ' (' . $this->l('test') . ')',
                        'name' => 'credentials_global[test][code]',
                        'class' => 'fixed-width-xxl',
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('Password') . ' (' . $this->l('test') . ')',
                        'name' => 'credentials_global[test][password]',
                        'class' => 'fixed-width-xxl',
                    ],
                ],
                'submit' => [
                    'title' => $this->l('Save'),
                ],
            ]
        );
    }

    /**
     * @return string
     */
    protected function displayGlobalPaymentBindingsForm()
    {
        $bindings = Tools::getValue('bindings_global');
        $savedBindings = $this->getSettings()->getGlobalPaymentBindings();
        $input = [];
        $fieldsValues = [];

        foreach (Language::getLanguages(false) as $language) {
            $languageId = $language['id_lang'];
            $fieldName = 'bindings_global[' . $languageId . ']';

            $input[] = [
                'type' => 'switch',
                'label' => $language['name'],
                'name' => $fieldName,
                'is_bool' => true,
                'values' => [
                    ['value' => 1],
                    ['value' => 0],
                ],
            ];

            $fieldsValues[$fieldName] = isset($bindings[$languageId]) ? $bindings[$languageId] :
                (isset($savedBindings[$languageId]) ? $savedBindings[$languageId] : '');
        }

        return $this->displaySettingsForm(
            'submit_global_payment_bindings',
            $fieldsValues,
            [
                'legend' => [
                    'title' => $this->l('Global Payment Bindings'),
                    'icon' => 'icon-cogs'
                ],
                'input' => $input,
                'submit' => [
                    'title' => $this->l('Save'),
                ],
            ]
        );
    }

    /**
     * @return int
     */
    protected function getSessionCount()
    {
        return (int)Db::getInstance()->getValue(
            (new DbQuery())
                ->select('COUNT(*)')
                ->from('avarda_session')
                ->build()
        );
    }

    /**
     * @param int $page
     * @param int $limit
     *
     * @return array
     */
    protected function getSessions($page = 1, $limit = 0)
    {
        AvardaSession::markExpired();

        $query = (new DbQuery())
            ->select('s.*, c.firstname, c.lastname, o.reference')
            ->from('avarda_session', 's')
            ->leftJoin('customer', 'c', 'c.id_customer = s.id_customer')
            ->leftJoin('orders', 'o', 'o.id_order = s.id_order')
            ->orderBy('s.date_add DESC');

        if ((int)$limit > 0) {
            $query->limit((int)$limit, (((int)$page > 0 ? (int)$page : 1) - 1) * (int)$limit);
        }

        if (!is_array($result = Db::getInstance()->executeS($query->build()))) {
            return [];
        }

        foreach ($result as $key => $item) {
            $result[$key] = [
                'id_session' => (int)$item['id_session'],
                'purchase_id' => $item['purchase_id'],
                'id_cart' => ($cartId = (int)$item['id_cart']),
                'cart_url' => $this->context->link->getAdminLink('AdminCarts', true, [], [
                    'viewcart' => 1,
                    'id_cart' => $cartId
                ]),
                'id_customer' => ($customerId = (int)$item['id_customer']),
                'customer_name' => trim($item['firstname'] . ' ' . $item['lastname']),
                'customer_url' => $this->context->link->getAdminLink('AdminCustomers', true, [], [
                    'viewcustomer' => 1,
                    'id_customer' => $customerId
                ]),
                'id_order' => ($orderId = (int)$item['id_order']),
                'order_reference' => $orderId ? $item['reference'] : '',
                'order_url' => $orderId ? $this->context->link->getAdminLink('AdminOrders', true, [], [
                    'vieworder' => 1,
                    'id_order' => $orderId
                ]) : '',
                'global' => $item['global'],
                'status' => $item['status'],
                'date' => $item['date_add'],
            ];
        }

        return $result;
    }

    /**
     * @param HelperList $helper
     *
     * @return int
     */
    protected function getListPagination(HelperList $helper)
    {
        $pagination = (int)Tools::getValue($helper->table . '_pagination');

        if (in_array($pagination, $helper->_pagination)) {
            return $pagination;
        }

        return (int)$helper->_default_pagination;
    }

    /**
     * @param HelperList $helper
     *
     * @return int
     */
    protected function getListPage(HelperList $helper)
    {
        $page = (int)Tools::getValue('submitFilter' . $helper->table, 1);
        $pagination = $this->getListPagination($helper);

        if ($page > ($totalPages = max(1, ceil($helper->listTotal / $pagination)))) {
            return $totalPages;
        }

        return $page;
    }

    /**
     * @param string $value
     * @param array $row
     *
     * @return string
     */
    public function displaySessionListCartLink($value, array $row)
    {
        return '<a href="' . $row['cart_url'] . '" class="session-list-link">#' . $value . '</a>';
    }

    /**
     * @param string $value
     * @param array $row
     *
     * @return string
     */
    public function displaySessionListCustomerLink($value, array $row)
    {
        return '<a href="' . $row['customer_url'] . '" class="session-list-link">' . $row['customer_name'] . '</a>';
    }

    /**
     * @param string $value
     * @param array $row
     *
     * @return string
     */
    public function displaySessionListOrderLink($value, array $row)
    {
        if (!$value) {
            return '--';
        }

        return '<a href="' . $row['order_url'] . '" class="session-list-link">' . $row['order_reference'] . '</a>';
    }

    /**
     * @param string $value
     * @param array $row
     *
     * @return string
     */
    public function displayGlobalPaymentStatus($value, array $row)
    {
        return $value ? $this->l('Yes') : $this->l('No');
    }

    /**
     * @param string $value
     * @param array $row
     *
     * @return string
     */
    public function displaySessionStatus($value, array $row)
    {
        $text = '';

        switch ($value) {
            case 'new':
                $text = $this->l('New');
                break;

            case 'processing':
                $text = $this->l('Processing');
                break;

            case 'completed':
                $text = $this->l('Completed');
                break;

            case 'canceled':
                $text = $this->l('Canceled');
                break;

            case 'expired':
                $text = $this->l('Expired');
                break;

            case 'error':
                $text = $this->l('Error');
                break;
        }

        if (!$text) {
            $value = 'unknown';
            $text = $this->l('Unknown');
        }

        return '<div class="session-status session-status-' . $value . '">' . $text . '</div>';
    }

    /**
     * @return string
     */
    protected function displaySessionList()
    {
        $helper = new HelperList();
        $helper->title = $this->l('Checkout Sessions');
        $helper->table = 'avarda_session';
        $helper->identifier = 'id_session';
        $helper->currentIndex = $this->getAdminLink();
        $helper->token = $this->getAdminToken();
        $helper->actions = [];
        $helper->module = $this;
        $helper->shopLinkType = '';
        $helper->_pagination = [10, 20, 50, 100, 300, 1000];
        $helper->_default_pagination = 10;
        $helper->listTotal = $this->getSessionCount();
        $helper->no_link = true;

        return $helper->generateList(
            $this->getSessions(
                $this->getListPage($helper),
                $this->getListPagination($helper)
            ),
            [
                'id_session' => [
                    'title' => $this->l('ID'),
                    'orderby' => false,
                    'search' => false,
                ],
                'purchase_id' => [
                    'title' => $this->l('Purchase ID'),
                    'orderby' => false,
                    'search' => false,
                ],
                'id_cart' => [
                    'title' => $this->l('Cart'),
                    'orderby' => false,
                    'search' => false,
                    'callback' => 'displaySessionListCartLink',
                    'callback_object' => $this,
                ],
                'id_customer' => [
                    'title' => $this->l('Customer'),
                    'orderby' => false,
                    'search' => false,
                    'callback' => 'displaySessionListCustomerLink',
                    'callback_object' => $this,
                ],
                'id_order' => [
                    'title' => $this->l('Order'),
                    'orderby' => false,
                    'search' => false,
                    'callback' => 'displaySessionListOrderLink',
                    'callback_object' => $this,
                ],
                'global' => [
                    'title' => $this->l('Global'),
                    'orderby' => false,
                    'search' => false,
                    'callback' => 'displayGlobalPaymentStatus',
                    'callback_object' => $this,
                ],
                'status' => [
                    'title' => $this->l('Status'),
                    'orderby' => false,
                    'search' => false,
                    'callback' => 'displaySessionStatus',
                    'callback_object' => $this,
                ],
                'date' => [
                    'title' => $this->l('Date'),
                    'orderby' => false,
                    'search' => false,
                ],
            ]
        );
    }

    /**
     * @return string
     *
     * @throws PrestaShopException
     */
    public function getContent()
    {
        $html = '';
        $errors = [];

        if (Tools::isSubmit('submit_general_settings')) {
            $this->saveGeneralSettings($errors);
        } elseif (Tools::isSubmit('submit_payment_api_credentials')) {
            $this->savePaymentApiCredentials($errors);
        } elseif (Tools::isSubmit('submit_global_payment_api_credentials')) {
            $this->saveGlobalPaymentApiCredentials($errors);
        } elseif (Tools::isSubmit('submit_global_payment_bindings')) {
            $this->saveGlobalPaymentBindings($errors);
        }

        foreach ($errors as $error) {
            $html .= $this->displayError($error);
        }

        $html .= $this->displayGeneralSettingsForm();
        $html .= $this->displayPaymentApiCredentialsForm();
        $html .= $this->displayGlobalPaymentApiCredentialsForm();
        $html .= $this->displayGlobalPaymentBindingsForm();
        $html .= $this->displaySessionList();

        return $html;
    }

    /**
     * @param mixed $params
     */
    public function hookActionAdminControllerSetMedia($params)
    {
        if ((Tools::getValue('controller') === 'AdminModules') && (Tools::getValue('configure') === $this->name)) {
            $this->context->controller->addCSS($this->_path . 'views/css/settings.css');
        }
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
    public function hookPaymentOptions($params)
    {
        if (!$this->active) {
            return null;
        }

        $settings = $this->getSettings();
        $bindings = $settings->getGlobalPaymentBindings();
        $globalPayment = isset($bindings[$this->context->language->id]) &&
            $bindings[$this->context->language->id];
        if ($globalPayment ? !$settings->hasGlobalPaymentCredentials() : !$settings->hasCredentials()) {
            return null;
        }

        // If settings has not module information.
        if (!$settings->getModuleInfo()) {
            $moduleName = 'Avarda';
            $moduleDescription = 'Payment option';
        } else {
            $moduleInfo = $settings->getModuleInfo();

            $moduleName = $moduleInfo['moduleName'];
            $moduleDescription = $moduleInfo['moduleDescription'];
        }

        // In case of default name, use variables that might have translations
        if ($moduleName === 'Avarda') {
            $moduleName = $this->moduleNameTranslatable;
        }

        if ($moduleDescription === 'Payment option') {
            $moduleDescription = $this->moduleDescriptionTranslatable;
        }

        // Looks weird but we need to parse new line from json to enter to make css pre-line work.
        $parsedModuleDescription = str_replace('\n', '
        ', $moduleDescription);

        $this->smarty->assign('logoUrl', $this->getUserLogoUrl());

        $this->smarty->assign('description', $parsedModuleDescription);
        $option = new PaymentOption();
        $option->setCallToActionText($moduleName)
            ->setAction($this->context->link->getModuleLink($this->name, 'checkout', [], true))
            ->setAdditionalInformation($this->display(__FILE__, 'views/templates/hook/payment-option.tpl'))
            ->setLogo(null);

        return [
            $option,
        ];
    }

    /**
     * @param $params
     *
     * @throws PrestaShopDatabaseException
     * @throws PrestaShopException
     * @throws \AvardaPayments\AvardaException
     */
    public function hookActionOrderStatusUpdate($params)
    {
        if (!isset($params['newOrderStatus'])) {
            return;
        }
        if (!isset($params['id_order'])) {
            return;
        }
        $orderState = $params['newOrderStatus'];
        if (!Validate::isLoadedObject($orderState)) {
            return;
        }
        if ($this->getSettings()->getDeliveryStatus() !== (int) $orderState->id) {
            return;
        }
        $id_order = (int) $params['id_order'];
        $order = new Order($id_order);
        if (Validate::isLoadedObject($order)) {
            $session = AvardaSession::getForOrder($order);
            if ($session) {
                $this->getOrderManager()->delivery($order);
            }
        }
    }

    public function hookOrderConfirmation($params)
    {
        if ($this->active && !in_array($params['order']->getCurrentState(), [
            $this->getSettings()->getCompletedStatus(),
            Configuration::get(self::OS_WAITING_FOR_PAYMENT),
            Configuration::get('PS_OS_OUTOFSTOCK'),
            Configuration::get('PS_OS_OUTOFSTOCK_UNPAID'),
        ])) {
            return $this->display(__FILE__, '/views/templates/hook/order-confirmation.tpl');
        }
    }

    /**
     * @param $params
     *
     * @return string
     *
     * @throws PrestaShopDatabaseException
     * @throws PrestaShopException
     * @throws \AvardaPayments\AvardaException
     */
    public function hookDisplayAdminOrder($params)
    {
        if (!isset($params['id_order'])) {
            return null;
        }
        $id_order = (int) $params['id_order'];
        $order = new Order($id_order);
        try {
            $manager = $this->getOrderManager();
        } catch (Exception $e) {
            // this can happen quite a lot, no point in logging this (at least here)
            return null;
        }
        if (!$manager->isAvardaOrder($order)) {
            return null;
        }
        $context = Context::getContext();
        $langId = (int) $context->language->id;
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

    /**
     * @param array $params
     *
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
                        'Description' => \AvardaPayments\Utils::maxChars(sprintf($this->l('Return: %s x %s'), $qty, $orderDetail['product_name']), 35),
                    ];
                }

                $manager->returnItems($order, $items);
            }
        }
    }

    /**
     * @return \AvardaPayments\OrderManager
     *
     * @throws \AvardaPayments\AvardaException
     */
    public function getOrderManager()
    {
        return new \AvardaPayments\OrderManager($this);
    }

    /**
     * @param bool $globalPayment
     *
     * @return AvardaPaymentsApi
     */
    public function getApi($globalPayment)
    {
        $settings = $this->getSettings();
        $credentials = $globalPayment ? $settings->getGlobalPaymentCredentials() : $settings->getCredentials();

        return new AvardaPaymentsApi($settings->getMode(), $credentials['code'], $credentials['password']);
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
     *
     * @return string
     */
    public function getPath($relative)
    {
        $uri = rtrim($this->getPathUri(), '/');
        $rel = ltrim($relative, '/');

        return "$uri/$rel";
    }

    /**
     * Validate an order in database
     * Function called from a payment module.
     *
     * @param int $id_cart
     * @param int $id_order_state
     * @param float $amount_paid Amount really paid by customer (in the default currency)
     * @param string $payment_method Payment method (eg. 'Credit card')
     * @param null $message Message to attach to order
     * @param array $extra_vars
     * @param null $currency_special
     * @param bool $dont_touch_amount
     * @param bool $secure_key
     * @param Shop $shop
     *
     * @return bool
     *
     * @throws PrestaShopException
     */
    public function validateOrder(
        $id_cart,
        $id_order_state,
        $amount_paid,
        $payment_method = 'Unknown',
        $message = null,
        $extra_vars = array(),
        $currency_special = null,
        $dont_touch_amount = false,
        $secure_key = false,
        Shop $shop = null
    ) {
        if (self::DEBUG_MODE) {
            PrestaShopLogger::addLog('PaymentModule::validateOrder - Function called', 1, null, 'Cart', (int) $id_cart, true);
        }

        if (!isset($this->context)) {
            $this->context = Context::getContext();
        }
        $this->context->cart = new Cart((int) $id_cart);
        $this->context->customer = new Customer((int) $this->context->cart->id_customer);
        // The tax cart is loaded before the customer so re-cache the tax calculation method
        $this->context->cart->setTaxCalculationMethod();

        $this->context->language = new Language((int) $this->context->cart->id_lang);
        $this->context->shop = ($shop ? $shop : new Shop((int) $this->context->cart->id_shop));
        ShopUrl::resetMainDomainCache();
        $id_currency = $currency_special ? (int) $currency_special : (int) $this->context->cart->id_currency;
        $this->context->currency = new Currency((int) $id_currency, null, (int) $this->context->shop->id);
        if (Configuration::get('PS_TAX_ADDRESS_TYPE') == 'id_address_delivery') {
            $context_country = $this->context->country;
        }

        $order_status = new OrderState((int) $id_order_state, (int) $this->context->language->id);
        if (!Validate::isLoadedObject($order_status)) {
            PrestaShopLogger::addLog('PaymentModule::validateOrder - Order Status cannot be loaded', 3, null, 'Cart', (int) $id_cart, true);
            throw new PrestaShopException('Can\'t load Order status');
        }

        if (!$this->active) {
            PrestaShopLogger::addLog('PaymentModule::validateOrder - Module is not active', 3, null, 'Cart', (int) $id_cart, true);
            die(Tools::displayError());
        }

        // Does order already exists ?
        if (Validate::isLoadedObject($this->context->cart) && $this->context->cart->OrderExists() == false) {
            if ($secure_key !== false && $secure_key != $this->context->cart->secure_key) {
                PrestaShopLogger::addLog('PaymentModule::validateOrder - Secure key does not match', 3, null, 'Cart', (int) $id_cart, true);
                die(Tools::displayError());
            }

            AvardaLog::createLog(self::LOG_NAME_VALIDATE_ORDER);

            AvardaLog::addDataToLog(self::LOG_NAME_VALIDATE_ORDER, array(
                'cart' => array(
                    'id' => $this->context->cart->id,
                    'id_customer' => $this->context->cart->id_customer,
                    'products' => $this->context->cart->getProducts(),
                )
            ));

            AvardaLog::addDataToLog(self::LOG_NAME_VALIDATE_ORDER, array(
                'customer' => array(
                    'id' => $this->context->customer->id,
                    'firstname' => $this->context->customer->firstname,
                    'lastname' => $this->context->customer->lastname,
                    'email' => $this->context->customer->email,
                )
            ));

            // For each package, generate an order
            $delivery_option_list = $this->context->cart->getDeliveryOptionList();
            $package_list = $this->context->cart->getPackageList();
            $cart_delivery_option = $this->context->cart->getDeliveryOption();

            // If some delivery options are not defined, or not valid, use the first valid option
            foreach ($delivery_option_list as $id_address => $package) {
                if (!isset($cart_delivery_option[$id_address]) || !array_key_exists($cart_delivery_option[$id_address], $package)) {
                    foreach ($package as $key => $val) {
                        $cart_delivery_option[$id_address] = $key;
                        break;
                    }
                }
            }

            $order_list = array();
            $order_detail_list = array();

            do {
                $reference = Order::generateReference();
            } while (Order::getByReference($reference)->count());

            $this->currentOrderReference = $reference;

            $cart_total_paid = (float) Tools::ps_round((float) $this->context->cart->getOrderTotal(true, Cart::BOTH), 2);

            foreach ($cart_delivery_option as $id_address => $key_carriers) {
                foreach ($delivery_option_list[$id_address][$key_carriers]['carrier_list'] as $id_carrier => $data) {
                    foreach ($data['package_list'] as $id_package) {
                        // Rewrite the id_warehouse
                        $package_list[$id_address][$id_package]['id_warehouse'] = (int) $this->context->cart->getPackageIdWarehouse($package_list[$id_address][$id_package], (int) $id_carrier);
                        $package_list[$id_address][$id_package]['id_carrier'] = $id_carrier;
                    }
                }
            }

            AvardaLog::addDataToLog(self::LOG_NAME_VALIDATE_ORDER, array(
                'delivery_option_list' => $delivery_option_list,
                'cart_delivery_option' => $cart_delivery_option,
                'package_list' => $package_list,
            ));

            // Make sure CartRule caches are empty
            CartRule::cleanCache();
            $cart_rules = $this->context->cart->getCartRules();
            foreach ($cart_rules as $cart_rule) {
                if (($rule = new CartRule((int) $cart_rule['obj']->id)) && Validate::isLoadedObject($rule)) {
                    if ($error = $rule->checkValidity($this->context, true, true)) {
                        $this->context->cart->removeCartRule((int) $rule->id);
                        if (isset($this->context->cookie) && isset($this->context->cookie->id_customer) && $this->context->cookie->id_customer && !empty($rule->code)) {
                            Tools::redirect('index.php?controller=order&submitAddDiscount=1&discount_name=' . urlencode($rule->code));
                        } else {
                            $rule_name = isset($rule->name[(int) $this->context->cart->id_lang]) ? $rule->name[(int) $this->context->cart->id_lang] : $rule->code;
                            $error = $this->trans('The cart rule named "%1s" (ID %2s) used in this cart is not valid and has been withdrawn from cart', array($rule_name, (int) $rule->id), 'Admin.Payment.Notification');
                            PrestaShopLogger::addLog($error, 3, '0000002', 'Cart', (int) $this->context->cart->id);
                        }
                    }
                }
            }

            AvardaLog::createLog(self::LOG_NAME_VALIDATE_ORDER_ORDERS);

            foreach ($package_list as $id_address => $packageByAddress) {
                foreach ($packageByAddress as $id_package => $package) {
                    /** @var Order $order */
                    $order = new Order();
                    $order->product_list = $package['product_list'];

                    if (Configuration::get('PS_TAX_ADDRESS_TYPE') == 'id_address_delivery') {
                        $address = new Address((int) $id_address);
                        $this->context->country = new Country((int) $address->id_country, (int) $this->context->cart->id_lang);
                        if (!$this->context->country->active) {
                            throw new PrestaShopException('The delivery address country is not active.');
                        }
                    }

                    $carrier = null;
                    if (!$this->context->cart->isVirtualCart() && isset($package['id_carrier'])) {
                        $carrier = new Carrier((int) $package['id_carrier'], (int) $this->context->cart->id_lang);
                        $order->id_carrier = (int) $carrier->id;
                        $id_carrier = (int) $carrier->id;
                    } else {
                        $order->id_carrier = 0;
                        $id_carrier = 0;
                    }

                    $order->id_customer = (int) $this->context->cart->id_customer;
                    $order->id_address_invoice = (int) $this->context->cart->id_address_invoice;
                    $order->id_address_delivery = (int) $id_address;
                    $order->id_currency = $this->context->currency->id;
                    $order->id_lang = (int) $this->context->cart->id_lang;
                    $order->id_cart = (int) $this->context->cart->id;
                    $order->reference = $reference;
                    $order->id_shop = (int) $this->context->shop->id;
                    $order->id_shop_group = (int) $this->context->shop->id_shop_group;

                    $order->secure_key = ($secure_key ? pSQL($secure_key) : pSQL($this->context->customer->secure_key));
                    $order->payment = $payment_method;
                    if (isset($this->name)) {
                        $order->module = $this->name;
                    }
                    $order->recyclable = $this->context->cart->recyclable;
                    $order->gift = (int) $this->context->cart->gift;
                    $order->gift_message = $this->context->cart->gift_message;
                    $order->mobile_theme = $this->context->cart->mobile_theme;
                    $order->conversion_rate = $this->context->currency->conversion_rate;
                    $amount_paid = !$dont_touch_amount ? Tools::ps_round((float) $amount_paid, 2) : $amount_paid;
                    $order->total_paid_real = 0;

                    $order->total_products = (float) $this->context->cart->getOrderTotal(false, Cart::ONLY_PRODUCTS, $order->product_list, $id_carrier);
                    $order->total_products_wt = (float) $this->context->cart->getOrderTotal(true, Cart::ONLY_PRODUCTS, $order->product_list, $id_carrier);
                    $order->total_discounts_tax_excl = (float) abs($this->context->cart->getOrderTotal(false, Cart::ONLY_DISCOUNTS, $order->product_list, $id_carrier));
                    $order->total_discounts_tax_incl = (float) abs($this->context->cart->getOrderTotal(true, Cart::ONLY_DISCOUNTS, $order->product_list, $id_carrier));
                    $order->total_discounts = $order->total_discounts_tax_incl;

                    $order->total_shipping_tax_excl = (float) $this->context->cart->getPackageShippingCost((int) $id_carrier, false, null, $order->product_list);
                    $order->total_shipping_tax_incl = (float) $this->context->cart->getPackageShippingCost((int) $id_carrier, true, null, $order->product_list);
                    $order->total_shipping = $order->total_shipping_tax_incl;

                    if (!is_null($carrier) && Validate::isLoadedObject($carrier)) {
                        $order->carrier_tax_rate = $carrier->getTaxesRate(new Address((int) $this->context->cart->{Configuration::get('PS_TAX_ADDRESS_TYPE')}));
                    }

                    $order->total_wrapping_tax_excl = (float) abs($this->context->cart->getOrderTotal(false, Cart::ONLY_WRAPPING, $order->product_list, $id_carrier));
                    $order->total_wrapping_tax_incl = (float) abs($this->context->cart->getOrderTotal(true, Cart::ONLY_WRAPPING, $order->product_list, $id_carrier));
                    $order->total_wrapping = $order->total_wrapping_tax_incl;

                    $order->total_paid_tax_excl = (float) Tools::ps_round((float) $this->context->cart->getOrderTotal(false, Cart::BOTH, $order->product_list, $id_carrier), _PS_PRICE_COMPUTE_PRECISION_);
                    $order->total_paid_tax_incl = (float) Tools::ps_round((float) $this->context->cart->getOrderTotal(true, Cart::BOTH, $order->product_list, $id_carrier), _PS_PRICE_COMPUTE_PRECISION_);
                    $order->total_paid = $order->total_paid_tax_incl;
                    $order->round_mode = Configuration::get('PS_PRICE_ROUND_MODE');
                    $order->round_type = Configuration::get('PS_ROUND_TYPE');

                    $order->invoice_date = '0000-00-00 00:00:00';
                    $order->delivery_date = '0000-00-00 00:00:00';

                    if (self::DEBUG_MODE) {
                        PrestaShopLogger::addLog('PaymentModule::validateOrder - Order is about to be added', 1, null, 'Cart', (int) $id_cart, true);
                    }

                    // Creating order
                    $result = $order->add();

                    if (!$result) {
                        PrestaShopLogger::addLog('PaymentModule::validateOrder - Order cannot be created', 3, null, 'Cart', (int) $id_cart, true);
                        throw new PrestaShopException('Can\'t save Order');
                    }

                    AvardaLog::addDataToLog(self::LOG_NAME_VALIDATE_ORDER_ORDERS, array(
                        array(
                            'id' => $order->id,
                            'id_carrier' => $order->id_carrier,
                            'id_customer' => $order->id_customer,
                            'id_address_invoice' => $order->id_address_invoice,
                            'id_address_delivery' => $order->id_address_delivery,
                            'id_currency' => $order->id_currency,
                            'id_lang' => $order->id_lang,
                            'id_cart' => $order->id_cart,
                            'id_shop' => $order->id_shop,
                            'id_shop_group' => $order->id_shop_group,
                            'id_warehouse' => $package_list[$id_address][$id_package]['id_warehouse'],
                            'reference' => $order->reference,
                            'payment' => $order->payment,
                            'module' => $order->module,
                            'product_list' => $order->product_list,
                        )
                    ));

                    // Amount paid by customer is not the right one -> Status = payment error
                    // We don't use the following condition to avoid the float precision issues : http://www.php.net/manual/en/language.types.float.php
                    // if ($order->total_paid != $order->total_paid_real)
                    // We use number_format in order to compare two string
                    if ($order_status->logable && number_format($cart_total_paid, _PS_PRICE_COMPUTE_PRECISION_) != number_format($amount_paid, _PS_PRICE_COMPUTE_PRECISION_)) {
                        $id_order_state = Configuration::get('PS_OS_ERROR');
                    }

                    $order_list[] = $order;

                    if (self::DEBUG_MODE) {
                        PrestaShopLogger::addLog('PaymentModule::validateOrder - OrderDetail is about to be added', 1, null, 'Cart', (int) $id_cart, true);
                    }

                    // Insert new Order detail list using cart for the current order
                    $order_detail = new OrderDetail(null, null, $this->context);
                    $order_detail->createList($order, $this->context->cart, $id_order_state, $order->product_list, 0, true, $package_list[$id_address][$id_package]['id_warehouse']);
                    $order_detail_list[] = $order_detail;

                    if (self::DEBUG_MODE) {
                        PrestaShopLogger::addLog('PaymentModule::validateOrder - OrderCarrier is about to be added', 1, null, 'Cart', (int) $id_cart, true);
                    }

                    // Adding an entry in order_carrier table
                    if (!is_null($carrier)) {
                        $order_carrier = new OrderCarrier();
                        $order_carrier->id_order = (int) $order->id;
                        $order_carrier->id_carrier = (int) $id_carrier;
                        $order_carrier->weight = (float) $order->getTotalWeight();
                        $order_carrier->shipping_cost_tax_excl = (float) $order->total_shipping_tax_excl;
                        $order_carrier->shipping_cost_tax_incl = (float) $order->total_shipping_tax_incl;
                        $order_carrier->add();
                    }
                }
            }

            AvardaLog::addDataToLog(self::LOG_NAME_VALIDATE_ORDER, array(
                'orders' => AvardaLog::getDataFromLog(self::LOG_NAME_VALIDATE_ORDER_ORDERS),
                'debug_backtrace' => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS),
            ));

            AvardaLog::saveLog(self::LOG_NAME_VALIDATE_ORDER);

            AvardaLog::clearLog(self::LOG_NAME_VALIDATE_ORDER);
            AvardaLog::clearLog(self::LOG_NAME_VALIDATE_ORDER_ORDERS);

            // The country can only change if the address used for the calculation is the delivery address, and if multi-shipping is activated
            if (Configuration::get('PS_TAX_ADDRESS_TYPE') == 'id_address_delivery') {
                $this->context->country = $context_country;
            }

            if (!$this->context->country->active) {
                PrestaShopLogger::addLog('PaymentModule::validateOrder - Country is not active', 3, null, 'Cart', (int) $id_cart, true);
                throw new PrestaShopException('The order address country is not active.');
            }

            if (self::DEBUG_MODE) {
                PrestaShopLogger::addLog('PaymentModule::validateOrder - Payment is about to be added', 1, null, 'Cart', (int) $id_cart, true);
            }

            // Register Payment only if the order status validate the order
            if ($order_status->logable) {
                // $order is the last order loop in the foreach
                // The method addOrderPayment of the class Order make a create a paymentOrder
                // linked to the order reference and not to the order id
                if (isset($extra_vars['transaction_id'])) {
                    $transaction_id = $extra_vars['transaction_id'];
                } else {
                    $transaction_id = null;
                }

                if (!$order->addOrderPayment($amount_paid, null, $transaction_id)) {
                    PrestaShopLogger::addLog('PaymentModule::validateOrder - Cannot save Order Payment', 3, null, 'Cart', (int) $id_cart, true);
                    throw new PrestaShopException('Can\'t save Order Payment');
                }
            }

            // Next !
            $only_one_gift = false;
            $cart_rule_used = array();
            $products = $this->context->cart->getProducts();

            // Make sure CartRule caches are empty
            CartRule::cleanCache();
            foreach ($order_detail_list as $key => $order_detail) {
                /** @var OrderDetail $order_detail */
                $order = $order_list[$key];
                if (isset($order->id)) {
                    if (!$secure_key) {
                        $message .= '<br />' . $this->trans('Warning: the secure key is empty, check your payment account before validation', array(), 'Admin.Payment.Notification');
                    }
                    // Optional message to attach to this order
                    if (isset($message) & !empty($message)) {
                        $msg = new Message();
                        $message = strip_tags($message, '<br>');
                        if (Validate::isCleanHtml($message)) {
                            if (self::DEBUG_MODE) {
                                PrestaShopLogger::addLog('PaymentModule::validateOrder - Message is about to be added', 1, null, 'Cart', (int) $id_cart, true);
                            }
                            $msg->message = $message;
                            $msg->id_cart = (int) $id_cart;
                            $msg->id_customer = (int) ($order->id_customer);
                            $msg->id_order = (int) $order->id;
                            $msg->private = 1;
                            $msg->add();
                        }
                    }

                    // Insert new Order detail list using cart for the current order
                    //$orderDetail = new OrderDetail(null, null, $this->context);
                    //$orderDetail->createList($order, $this->context->cart, $id_order_state);

                    // Construct order detail table for the email
                    $products_list = '';
                    $virtual_product = true;

                    $product_var_tpl_list = array();
                    foreach ($order->product_list as $product) {
                        $price = Product::getPriceStatic((int) $product['id_product'], false, ($product['id_product_attribute'] ? (int) $product['id_product_attribute'] : null), 6, null, false, true, $product['cart_quantity'], false, (int) $order->id_customer, (int) $order->id_cart, (int) $order->{Configuration::get('PS_TAX_ADDRESS_TYPE')}, $specific_price, true, true, null, true, $product['id_customization']);
                        $price_wt = Product::getPriceStatic((int) $product['id_product'], true, ($product['id_product_attribute'] ? (int) $product['id_product_attribute'] : null), 2, null, false, true, $product['cart_quantity'], false, (int) $order->id_customer, (int) $order->id_cart, (int) $order->{Configuration::get('PS_TAX_ADDRESS_TYPE')}, $specific_price, true, true, null, true, $product['id_customization']);

                        $product_price = Product::getTaxCalculationMethod() == PS_TAX_EXC ? Tools::ps_round($price, 2) : $price_wt;

                        $product_var_tpl = array(
                            'id_product' => $product['id_product'],
                            'reference' => $product['reference'],
                            'name' => $product['name'] . (isset($product['attributes']) ? ' - ' . $product['attributes'] : ''),
                            'price' => Tools::displayPrice($product_price * $product['quantity'], $this->context->currency, false),
                            'quantity' => $product['quantity'],
                            'customization' => array(),
                        );

                        if (isset($product['price']) && $product['price']) {
                            $product_var_tpl['unit_price'] = Tools::displayPrice($product_price, $this->context->currency, false);
                            $product_var_tpl['unit_price_full'] = Tools::displayPrice($product_price, $this->context->currency, false)
                                . ' ' . $product['unity'];
                        } else {
                            $product_var_tpl['unit_price'] = $product_var_tpl['unit_price_full'] = '';
                        }

                        $customized_datas = Product::getAllCustomizedDatas((int) $order->id_cart, null, true, null, (int) $product['id_customization']);
                        if (isset($customized_datas[$product['id_product']][$product['id_product_attribute']])) {
                            $product_var_tpl['customization'] = array();
                            foreach ($customized_datas[$product['id_product']][$product['id_product_attribute']][$order->id_address_delivery] as $customization) {
                                $customization_text = '';
                                if (isset($customization['datas'][Product::CUSTOMIZE_TEXTFIELD])) {
                                    foreach ($customization['datas'][Product::CUSTOMIZE_TEXTFIELD] as $text) {
                                        $customization_text .= '<strong>' . $text['name'] . '</strong>: ' . $text['value'] . '<br />';
                                    }
                                }

                                if (isset($customization['datas'][Product::CUSTOMIZE_FILE])) {
                                    $customization_text .= $this->trans('%d image(s)', array(count($customization['datas'][Product::CUSTOMIZE_FILE])), 'Admin.Payment.Notification') . '<br />';
                                }

                                $customization_quantity = (int) $customization['quantity'];

                                $product_var_tpl['customization'][] = array(
                                    'customization_text' => $customization_text,
                                    'customization_quantity' => $customization_quantity,
                                    'quantity' => Tools::displayPrice($customization_quantity * $product_price, $this->context->currency, false),
                                );
                            }
                        }

                        $product_var_tpl_list[] = $product_var_tpl;
                        // Check if is not a virutal product for the displaying of shipping
                        if (!$product['is_virtual']) {
                            $virtual_product &= false;
                        }
                    } // end foreach ($products)

                    $product_list_txt = '';
                    $product_list_html = '';
                    if (count($product_var_tpl_list) > 0) {
                        $product_list_txt = $this->getEmailTemplateContent('order_conf_product_list.txt', Mail::TYPE_TEXT, $product_var_tpl_list);
                        $product_list_html = $this->getEmailTemplateContent('order_conf_product_list.tpl', Mail::TYPE_HTML, $product_var_tpl_list);
                    }

                    $cart_rules_list = array();
                    $total_reduction_value_ti = 0;
                    $total_reduction_value_tex = 0;
                    foreach ($cart_rules as $cart_rule) {
                        $package = array('id_carrier' => $order->id_carrier, 'id_address' => $order->id_address_delivery, 'products' => $order->product_list);
                        $values = array(
                            'tax_incl' => $cart_rule['obj']->getContextualValue(true, $this->context, CartRule::FILTER_ACTION_ALL_NOCAP, $package),
                            'tax_excl' => $cart_rule['obj']->getContextualValue(false, $this->context, CartRule::FILTER_ACTION_ALL_NOCAP, $package),
                        );

                        // If the reduction is not applicable to this order, then continue with the next one
                        if (!$values['tax_excl']) {
                            continue;
                        }

                        // IF
                        //  This is not multi-shipping
                        //  The value of the voucher is greater than the total of the order
                        //  Partial use is allowed
                        //  This is an "amount" reduction, not a reduction in % or a gift
                        // THEN
                        //  The voucher is cloned with a new value corresponding to the remainder
                        if (count($order_list) == 1 && $values['tax_incl'] > ($order->total_products_wt - $total_reduction_value_ti) && $cart_rule['obj']->partial_use == 1 && $cart_rule['obj']->reduction_amount > 0) {
                            // Create a new voucher from the original
                            $voucher = new CartRule((int) $cart_rule['obj']->id); // We need to instantiate the CartRule without lang parameter to allow saving it
                            unset($voucher->id);

                            // Set a new voucher code
                            $voucher->code = empty($voucher->code) ? substr(md5($order->id . '-' . $order->id_customer . '-' . $cart_rule['obj']->id), 0, 16) : $voucher->code . '-2';
                            if (preg_match('/\-([0-9]{1,2})\-([0-9]{1,2})$/', $voucher->code, $matches) && $matches[1] == $matches[2]) {
                                $voucher->code = preg_replace('/' . $matches[0] . '$/', '-' . (intval($matches[1]) + 1), $voucher->code);
                            }

                            // Set the new voucher value
                            if ($voucher->reduction_tax) {
                                $voucher->reduction_amount = ($total_reduction_value_ti + $values['tax_incl']) - $order->total_products_wt;

                                // Add total shipping amout only if reduction amount > total shipping
                                if ($voucher->free_shipping == 1 && $voucher->reduction_amount >= $order->total_shipping_tax_incl) {
                                    $voucher->reduction_amount -= $order->total_shipping_tax_incl;
                                }
                            } else {
                                $voucher->reduction_amount = ($total_reduction_value_tex + $values['tax_excl']) - $order->total_products;

                                // Add total shipping amout only if reduction amount > total shipping
                                if ($voucher->free_shipping == 1 && $voucher->reduction_amount >= $order->total_shipping_tax_excl) {
                                    $voucher->reduction_amount -= $order->total_shipping_tax_excl;
                                }
                            }
                            if ($voucher->reduction_amount <= 0) {
                                continue;
                            }

                            if ($this->context->customer->isGuest()) {
                                $voucher->id_customer = 0;
                            } else {
                                $voucher->id_customer = $order->id_customer;
                            }

                            $voucher->quantity = 1;
                            $voucher->reduction_currency = $order->id_currency;
                            $voucher->quantity_per_user = 1;
                            if ($voucher->add()) {
                                // If the voucher has conditions, they are now copied to the new voucher
                                CartRule::copyConditions($cart_rule['obj']->id, $voucher->id);
                                $orderLanguage = new Language((int) $order->id_lang);

                                $params = array(
                                    '{voucher_amount}' => Tools::displayPrice($voucher->reduction_amount, $this->context->currency, false),
                                    '{voucher_num}' => $voucher->code,
                                    '{firstname}' => $this->context->customer->firstname,
                                    '{lastname}' => $this->context->customer->lastname,
                                    '{id_order}' => $order->reference,
                                    '{order_name}' => $order->getUniqReference(),
                                );
                                Mail::Send(
                                    (int) $order->id_lang,
                                    'voucher',
                                    Context::getContext()->getTranslator()->trans(
                                        'New voucher for your order %s',
                                        array($order->reference),
                                        'Emails.Subject',
                                        $orderLanguage->locale
                                    ),
                                    $params,
                                    $this->context->customer->email,
                                    $this->context->customer->firstname . ' ' . $this->context->customer->lastname,
                                    null, null, null, null, _PS_MAIL_DIR_, false, (int) $order->id_shop
                                );
                            }

                            $values['tax_incl'] = $order->total_products_wt - $total_reduction_value_ti;
                            $values['tax_excl'] = $order->total_products - $total_reduction_value_tex;
                            if (1 == $voucher->free_shipping) {
                                $values['tax_incl'] += $order->total_shipping_tax_incl;
                                $values['tax_excl'] += $order->total_shipping_tax_excl;
                            }
                        }
                        $total_reduction_value_ti += $values['tax_incl'];
                        $total_reduction_value_tex += $values['tax_excl'];

                        $order->addCartRule($cart_rule['obj']->id, $cart_rule['obj']->name, $values, 0, $cart_rule['obj']->free_shipping);

                        if ($id_order_state != Configuration::get('PS_OS_ERROR') && $id_order_state != Configuration::get('PS_OS_CANCELED') && !in_array($cart_rule['obj']->id, $cart_rule_used)) {
                            $cart_rule_used[] = $cart_rule['obj']->id;

                            // Create a new instance of Cart Rule without id_lang, in order to update its quantity
                            $cart_rule_to_update = new CartRule((int) $cart_rule['obj']->id);
                            $cart_rule_to_update->quantity = max(0, $cart_rule_to_update->quantity - 1);
                            $cart_rule_to_update->update();
                        }

                        $cart_rules_list[] = array(
                            'voucher_name' => $cart_rule['obj']->name,
                            'voucher_reduction' => ($values['tax_incl'] != 0.00 ? '-' : '') . Tools::displayPrice($values['tax_incl'], $this->context->currency, false),
                        );
                    }

                    $cart_rules_list_txt = '';
                    $cart_rules_list_html = '';
                    if (count($cart_rules_list) > 0) {
                        $cart_rules_list_txt = $this->getEmailTemplateContent('order_conf_cart_rules.txt', Mail::TYPE_TEXT, $cart_rules_list);
                        $cart_rules_list_html = $this->getEmailTemplateContent('order_conf_cart_rules.tpl', Mail::TYPE_HTML, $cart_rules_list);
                    }

                    // Specify order id for message
                    $old_message = Message::getMessageByCartId((int) $this->context->cart->id);
                    if ($old_message && !$old_message['private']) {
                        $update_message = new Message((int) $old_message['id_message']);
                        $update_message->id_order = (int) $order->id;
                        $update_message->update();

                        // Add this message in the customer thread
                        $customer_thread = new CustomerThread();
                        $customer_thread->id_contact = 0;
                        $customer_thread->id_customer = (int) $order->id_customer;
                        $customer_thread->id_shop = (int) $this->context->shop->id;
                        $customer_thread->id_order = (int) $order->id;
                        $customer_thread->id_lang = (int) $this->context->language->id;
                        $customer_thread->email = $this->context->customer->email;
                        $customer_thread->status = 'open';
                        $customer_thread->token = Tools::passwdGen(12);
                        $customer_thread->add();

                        $customer_message = new CustomerMessage();
                        $customer_message->id_customer_thread = $customer_thread->id;
                        $customer_message->id_employee = 0;
                        $customer_message->message = $update_message->message;
                        $customer_message->private = 1;

                        if (!$customer_message->add()) {
                            $this->errors[] = $this->trans('An error occurred while saving message', array(), 'Admin.Payment.Notification');
                        }
                    }

                    if (self::DEBUG_MODE) {
                        PrestaShopLogger::addLog('PaymentModule::validateOrder - Hook validateOrder is about to be called', 1, null, 'Cart', (int) $id_cart, true);
                    }

                    // Hook validate order
                    Hook::exec('actionValidateOrder', array(
                        'cart' => $this->context->cart,
                        'order' => $order,
                        'customer' => $this->context->customer,
                        'currency' => $this->context->currency,
                        'orderStatus' => $order_status,
                    ));

                    foreach ($this->context->cart->getProducts() as $product) {
                        if ($order_status->logable) {
                            ProductSale::addProductSale((int) $product['id_product'], (int) $product['cart_quantity']);
                        }
                    }

                    if (self::DEBUG_MODE) {
                        PrestaShopLogger::addLog('PaymentModule::validateOrder - Order Status is about to be added', 1, null, 'Cart', (int) $id_cart, true);
                    }

                    // Set the order status
                    $new_history = new OrderHistory();
                    $new_history->id_order = (int) $order->id;
                    $new_history->changeIdOrderState((int) $id_order_state, $order, true);
                    $new_history->addWithemail(true, $extra_vars);

                    // Switch to back order if needed
                    if (Configuration::get('PS_STOCK_MANAGEMENT') &&
                            ($order_detail->getStockState() ||
                            $order_detail->product_quantity_in_stock < 0)) {
                        $history = new OrderHistory();
                        $history->id_order = (int) $order->id;
                        $history->changeIdOrderState(Configuration::get($order->valid ? 'PS_OS_OUTOFSTOCK_PAID' : 'PS_OS_OUTOFSTOCK_UNPAID'), $order, true);
                        $history->addWithemail();
                    }

                    unset($order_detail);

                    // Order is reloaded because the status just changed
                    $order = new Order((int) $order->id);

                    // Send an e-mail to customer (one order = one email)
                    if ($id_order_state != Configuration::get('PS_OS_ERROR') && $id_order_state != Configuration::get('PS_OS_CANCELED') && $this->context->customer->id) {
                        $invoice = new Address((int) $order->id_address_invoice);
                        $delivery = new Address((int) $order->id_address_delivery);
                        $delivery_state = $delivery->id_state ? new State((int) $delivery->id_state) : false;
                        $invoice_state = $invoice->id_state ? new State((int) $invoice->id_state) : false;

                        $data = array(
                        '{firstname}' => $this->context->customer->firstname,
                        '{lastname}' => $this->context->customer->lastname,
                        '{email}' => $this->context->customer->email,
                        '{delivery_block_txt}' => $this->_getFormatedAddress($delivery, "\n"),
                        '{invoice_block_txt}' => $this->_getFormatedAddress($invoice, "\n"),
                        '{delivery_block_html}' => $this->_getFormatedAddress($delivery, '<br />', array(
                            'firstname' => '<span style="font-weight:bold;">%s</span>',
                            'lastname' => '<span style="font-weight:bold;">%s</span>',
                        )),
                        '{invoice_block_html}' => $this->_getFormatedAddress($invoice, '<br />', array(
                                'firstname' => '<span style="font-weight:bold;">%s</span>',
                                'lastname' => '<span style="font-weight:bold;">%s</span>',
                        )),
                        '{delivery_company}' => $delivery->company,
                        '{delivery_firstname}' => $delivery->firstname,
                        '{delivery_lastname}' => $delivery->lastname,
                        '{delivery_address1}' => $delivery->address1,
                        '{delivery_address2}' => $delivery->address2,
                        '{delivery_city}' => $delivery->city,
                        '{delivery_postal_code}' => $delivery->postcode,
                        '{delivery_country}' => $delivery->country,
                        '{delivery_state}' => $delivery->id_state ? $delivery_state->name : '',
                        '{delivery_phone}' => ($delivery->phone) ? $delivery->phone : $delivery->phone_mobile,
                        '{delivery_other}' => $delivery->other,
                        '{invoice_company}' => $invoice->company,
                        '{invoice_vat_number}' => $invoice->vat_number,
                        '{invoice_firstname}' => $invoice->firstname,
                        '{invoice_lastname}' => $invoice->lastname,
                        '{invoice_address2}' => $invoice->address2,
                        '{invoice_address1}' => $invoice->address1,
                        '{invoice_city}' => $invoice->city,
                        '{invoice_postal_code}' => $invoice->postcode,
                        '{invoice_country}' => $invoice->country,
                        '{invoice_state}' => $invoice->id_state ? $invoice_state->name : '',
                        '{invoice_phone}' => ($invoice->phone) ? $invoice->phone : $invoice->phone_mobile,
                        '{invoice_other}' => $invoice->other,
                        '{order_name}' => $order->getUniqReference(),
                        '{date}' => Tools::displayDate(date('Y-m-d H:i:s'), null, 1),
                        '{carrier}' => ($virtual_product || !isset($carrier->name)) ? $this->trans('No carrier', array(), 'Admin.Payment.Notification') : $carrier->name,
                        '{payment}' => Tools::substr($order->payment, 0, 255),
                        '{products}' => $product_list_html,
                        '{products_txt}' => $product_list_txt,
                        '{discounts}' => $cart_rules_list_html,
                        '{discounts_txt}' => $cart_rules_list_txt,
                        '{total_paid}' => Tools::displayPrice($order->total_paid, $this->context->currency, false),
                        '{total_products}' => Tools::displayPrice(Product::getTaxCalculationMethod() == PS_TAX_EXC ? $order->total_products : $order->total_products_wt, $this->context->currency, false),
                        '{total_discounts}' => Tools::displayPrice($order->total_discounts, $this->context->currency, false),
                        '{total_shipping}' => Tools::displayPrice($order->total_shipping, $this->context->currency, false),
                        '{total_wrapping}' => Tools::displayPrice($order->total_wrapping, $this->context->currency, false),
                        '{total_tax_paid}' => Tools::displayPrice(($order->total_products_wt - $order->total_products) + ($order->total_shipping_tax_incl - $order->total_shipping_tax_excl), $this->context->currency, false), );

                        if (is_array($extra_vars)) {
                            $data = array_merge($data, $extra_vars);
                        }

                        // Join PDF invoice
                        if ((int) Configuration::get('PS_INVOICE') && $order_status->invoice && $order->invoice_number) {
                            $order_invoice_list = $order->getInvoicesCollection();
                            Hook::exec('actionPDFInvoiceRender', array('order_invoice_list' => $order_invoice_list));
                            $pdf = new PDF($order_invoice_list, PDF::TEMPLATE_INVOICE, $this->context->smarty);
                            $file_attachement['content'] = $pdf->render(false);
                            $file_attachement['name'] = Configuration::get('PS_INVOICE_PREFIX', (int) $order->id_lang, null, $order->id_shop) . sprintf('%06d', $order->invoice_number) . '.pdf';
                            $file_attachement['mime'] = 'application/pdf';
                        } else {
                            $file_attachement = null;
                        }

                        if (self::DEBUG_MODE) {
                            PrestaShopLogger::addLog('PaymentModule::validateOrder - Mail is about to be sent', 1, null, 'Cart', (int) $id_cart, true);
                        }

                        $orderLanguage = new Language((int) $order->id_lang);

                        if (Validate::isEmail($this->context->customer->email)) {
                            Mail::Send(
                                (int) $order->id_lang,
                                'order_conf',
                                Context::getContext()->getTranslator()->trans(
                                    'Order confirmation',
                                    array(),
                                    'Emails.Subject',
                                    $orderLanguage->locale
                                ),
                                $data,
                                $this->context->customer->email,
                                $this->context->customer->firstname . ' ' . $this->context->customer->lastname,
                                null,
                                null,
                                $file_attachement,
                                null, _PS_MAIL_DIR_, false, (int) $order->id_shop
                            );
                        }
                    }

                    // updates stock in shops
                    if (Configuration::get('PS_ADVANCED_STOCK_MANAGEMENT')) {
                        $product_list = $order->getProducts();
                        foreach ($product_list as $product) {
                            // if the available quantities depends on the physical stock
                            if (StockAvailable::dependsOnStock($product['product_id'])) {
                                // synchronizes
                                StockAvailable::synchronize($product['product_id'], $order->id_shop);
                            }
                        }
                    }

                    $order->updateOrderDetailTax();

                    // sync all stock
                    (new StockManager())->updatePhysicalProductQuantity(
                        (int) $order->id_shop,
                        (int) Configuration::get('PS_OS_ERROR'),
                        (int) Configuration::get('PS_OS_CANCELED'),
                        null,
                        (int) $order->id
                    );
                } else {
                    $error = $this->trans('Order creation failed', array(), 'Admin.Payment.Notification');
                    PrestaShopLogger::addLog($error, 4, '0000002', 'Cart', intval($order->id_cart));
                    die($error);
                }
            } // End foreach $order_detail_list

            // Use the last order as currentOrder
            if (isset($order) && $order->id) {
                $this->currentOrder = (int) $order->id;
            }

            if (self::DEBUG_MODE) {
                PrestaShopLogger::addLog('PaymentModule::validateOrder - End of validateOrder', 1, null, 'Cart', (int) $id_cart, true);
            }

            return true;
        } else {
            $error = $this->trans('Cart cannot be loaded or an order has already been placed using this cart', array(), 'Admin.Payment.Notification');
            PrestaShopLogger::addLog($error, 4, '0000001', 'Cart', intval($this->context->cart->id));
            die($error);
        }
    }

    /**
     * @param mixed $params
     */
    public function hookActionObjectCustomerAddAfter($params)
    {
        if (isset($params['object']) && ($params['object'] instanceof Customer)) {
            AvardaCustomerLog::addLog($params['object'], array(
                'debug_backtrace' => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS)
            ));
        }
    }

    /**
     * @param mixed $params
     */
    public function hookActionObjectCustomerUpdateAfter($params)
    {
        if (isset($params['object']) && ($params['object'] instanceof Customer)) {
            AvardaCustomerLog::addLog($params['object'], array(
                'debug_backtrace' => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS)
            ));
        }
    }
}
