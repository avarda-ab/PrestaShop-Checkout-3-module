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

class AppTranslation
{
    private $module;

    /**
     * AppTranslation constructor.
     *
     * @param $module
     */
    public function __construct($module)
    {
        $this->module = $module;
    }

    /**
     * @return array
     */
    public function getTranslations()
    {
        return $this->changed([
            'API credentials' => $this->l('API credentials'),
            'Be aware, you are in production mode' => $this->l('Be aware, you are in production mode'),
            'Cancel' => $this->l('Cancel'),
            'Cart' => $this->l('Cart'),
            'Checkout sessions (%s)' => $this->l('Checkout sessions (%s)'),
            'Checkout sessions' => $this->l('Checkout sessions'),
            'Close' => $this->l('Close'),
            'Completed status' => $this->l('Completed status'),
            'Customer' => $this->l('Customer'),
            'Date' => $this->l('Date'),
            'Delivery status' => $this->l('Delivery status'),
            'Error - invalid credentials' => $this->l('Error - invalid credentials'),
            'Failed to load sessions' => $this->l('Failed to load sessions'),
            'Loading data, please wait...' => $this->l('Loading data, please wait...'),
            'Mode' => $this->l('Mode'),
            'Next Page' => $this->l('Next Page'),
            'No session sessions were found' => $this->l('No session sessions were found'),
            'Order status when payment has been successfully completed' => $this->l('Order status when payment has been successfully completed'),
            'Order statuses' => $this->l('Order statuses'),
            'Order' => $this->l('Order'),
            'Please enter your username' => $this->l('Please enter your username'),
            'Previous Page' => $this->l('Previous Page'),
            'Production mode' => $this->l('Production mode'),
            'Save changes' => $this->l('Save changes'),
            'Session' => $this->l('Session'),
            'Sessions' => $this->l('Sessions'),
            'Settings has been saved' => $this->l('Settings has been saved'),
            'Settings' => $this->l('Settings'),
            'Status' => $this->l('Status'),
            'Success - credentials are valid' => $this->l('Success - credentials are valid'),
            'Test credentials' => $this->l('Test credentials'),
            'Test mode allows you to verify avarda integration against staging environment' => $this->l('Test mode allows you to verify avarda integration against staging environment'),
            'Test mode' => $this->l('Test mode'),
            'Username' => $this->l('Username'),
            'When order transition to this status, avarda Purchase Order will be created' => $this->l('When order transition to this status, avarda Purchase Order will be created'),
        ]);
    }

    /**
     * @param stirng $str
     *
     * @return string
     */
    public function l($str)
    {
        return html_entity_decode($this->module->l($str, 'app-translation'));
    }

    /**
     * @param $array
     *
     * @return array
     */
    private function changed($array)
    {
        $ret = [];
        foreach ($array as $key => $value) {
            if ($value != $key) {
                $ret[$key] = $value;
            }
        }

        return $ret;
    }
}
