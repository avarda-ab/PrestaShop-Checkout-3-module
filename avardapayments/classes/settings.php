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

use \Configuration;

class Settings
{
    const SETTINGS = 'AVARDA_SETTINGS';
    const BACKEND_APP_URL = 'AVARDA_BACK_URL';


    private $data;

    public function __construct()
    {
        $this->data = self::getDefaultSettings();
        $stored = Configuration::get(self::SETTINGS);
        if ($stored) {
            $stored = json_decode($stored, true);
            if ($stored) {
                $this->data = self::mergeSettings($this->data, $stored);
            }
        }
    }

    /**
     * @return array
     */
    private static function getDefaultSettings()
    {
        return [
            'mode' => 'test',
            'credentials' => [
                'test' => [
                    'code' => '',
                    'password' => ''
                ],
                'production' => [
                    'code' => '',
                    'password' => ''
                ]
            ],
            'showCart' => true,
            'completedStatus' => 2,
            'deliveryStatus' => 5,
            'useOnePage' => 0,
            'moduleInfo' => [
                "moduleName" => "Avarda",
                "moduleDescription" => "Payment module"
            ]
        ];
    }

    /**
     * @return array
     */
    public function getModuleInfo()
    {
        return $this->get(['moduleInfo']);
    }

    /**
     * @return array
     */
    public function getCredentials()
    {
        $mode = $this->getMode();
        return $this->get(['credentials', $mode]);
    }

    /**
     * Returns true if credentials are set
     *
     * @return bool
     */
    public function hasCredentials()
    {
        $mode = $this->getMode();
        $code = $this->get(['credentials', $mode, 'code']);
        $password = $this->get(['credentials', $mode, 'password']);
        return ($code && $password);
    }

    /**
     * @return string
     */
    public function getMode()
    {
        return $this->get(['mode']);
    }

    public function getCompletedStatus()
    {
        return (int)$this->get(['completedStatus']);
    }

    public function getDeliveryStatus()
    {
        return (int)$this->get(['deliveryStatus']);
    }

    public function getUseOnePage()
    {
        return !!$this->get(['useOnePage']);
    }

    /**
     * Returns true if we run in test mode
     * @return bool
     */
    public function isTestMode()
    {
        return !$this->isProductionMode();
    }

    /**
     * @return bool
     */
    public function isProductionMode()
    {
        return $this->getMode() === 'production';
    }

    /**
     * @return bool
     */
    public function showCart()
    {
        return !!$this->get(['showCart']);
    }

    /**
     * Initialize settings object
     *
     * @return bool
     */
    public function init()
    {
        return $this->set(self::getDefaultSettings());
    }

    /**
     * Resets settings object
     *
     * @return bool
     */
    public function reset()
    {
        return $this->remove() && $this->init();
    }

    /**
     * Deletes settings from database
     *
     * @return bool
     */
    public function remove()
    {
        $this->data = null;
        Configuration::deleteByName(self::SETTINGS);
        return true;
    }

    /**
     * @param null $path
     * @return array|mixed
     */
    public function get($path = null)
    {
        $value = $this->data;
        if (is_null($path)) {
            return $value;
        }
        foreach ($path as $key) {
            if (isset($value[$key])) {
                $value = $value[$key];
            } else {
                die('Avarda: setting not found: ' . implode($path, '>'));
            }
        }
        return $value;
    }

    /**
     * Merges two setting objects
     *
     * @param array $left
     * @param array $right
     * @return array
     */
    private static function mergeSettings($left, $right)
    {
        $ret = [];
        foreach ($left as $key => $value) {
            if (isset($right[$key])) {
                if (is_array($value)) {
                    $value = self::mergeSettings($value, $right[$key]);
                } else {
                    $value = $right[$key];
                }
            }
            $ret[$key] = $value;
        }
        return $ret;
    }

    /**
     * Updates settings value
     *
     * @param mixed $value
     * @return bool
     */
    public function set($value)
    {
        $this->data = $value;
        return Configuration::updateValue(self::SETTINGS, json_encode($value));
    }

    /**
     * @param \AvardaPayments $module
     * @return string
     */
    public function getBackendAppUrl($module)
    {
        $url = Configuration::get(self::BACKEND_APP_URL);
        if (!$url) {
            $version = self::getUnderscoredVersion($module);
            $url = $module->getPath("views/js/back-{$version}.js");
        }
        return $url;
    }

    /**
     * @param $module
     * @return mixed
     */
    private static function getUnderscoredVersion($module)
    {
        return str_replace('.', '_', $module->version);
    }

}
