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

use AvardaPayments\Utils;

class AvardaSession extends ObjectModel
{
    // session timout is seconds
    const SESSION_TIMEOUT = 30 * 60;

    public static $definition = [
        'table' => 'avarda_session',
        'primary' => 'id_session',
        'fields' => [
            'id_customer' => ['type' => self::TYPE_INT, 'required' => true],
            'id_cart' => ['type' => self::TYPE_INT, 'required' => true],
            'id_order' => ['type' => self::TYPE_INT],
            'purchase_id' => ['type' => self::TYPE_STRING],
            'purchase_token' => ['type' => self::TYPE_STRING],
            'purchase_expire_timestamp' => ['type' => self::TYPE_DATE],
            'cart_signature' => ['type' => self::TYPE_STRING, 'required' => true],
            'status' => ['type' => self::TYPE_STRING, 'required' => true, 'values' => ['new', 'processing', 'completed', 'error', 'canceled']],
            'mode' => ['type' => self::TYPE_STRING, 'required' => true, 'values' => ['test', 'production']],
            'global' => ['type' => self::TYPE_BOOL, 'required' => true, 'validate' => 'isBool'],
            'info' => ['type' => self::TYPE_STRING],
            'error_message' => ['type' => self::TYPE_STRING],
            'date_add' => ['type' => self::TYPE_DATE],
            'date_upd' => ['type' => self::TYPE_DATE],
            'create_customer' => ['type' => self::TYPE_BOOL, 'validate' => 'isBool'],
            'newsletter' => ['type' => self::TYPE_BOOL, 'validate' => 'isBool'],
            'passwd' => ['type' => self::TYPE_STRING, 'validate' => 'isPasswd', 'size' => 255],
            'id_carrier' => ['type' => self::TYPE_INT],
            'order_message' => ['type' => self::TYPE_STRING],
            'is_recycled' => ['type' => self::TYPE_BOOL, 'validate' => 'isBool'],
            'is_gift' => ['type' => self::TYPE_BOOL, 'validate' => 'isBool'],
            'gift_message' => ['type' => self::TYPE_STRING],
        ],
    ];

    public $id_customer;
    public $id_cart;
    public $id_order;
    public $purchase_id;
    public $purchase_token;
    public $purchase_expire_timestamp;
    public $cart_signature;
    public $status;
    public $error_message;
    public $info;
    public $mode;
    public $global;
    public $date_add;
    public $date_upd;
    public $create_customer;
    public $newsletter;
    public $passwd;
    public $id_carrier;
    public $order_message;
    public $is_recycled;
    public $is_gift;
    public $gift_message;

    /**
     * @param Order $order
     *
     * @return AvardaSession|null
     */
    public static function getForOrder(Order $order)
    {
        $sql = (new DbQuery())
            ->select('id_session')
            ->from('avarda_session')
            ->where('id_order = ' . (int) $order->id);
        $id = (int) Db::getInstance()->getValue($sql, false);

        return $id ? new AvardaSession($id) : null;
    }

    /**
     * @param $purchaseId
     *
     * @return AvardaSession|null
     */
    public static function getForPurchaseId($purchaseId)
    {
        $sql = (new DbQuery())
            ->select('id_session')
            ->from('avarda_session')
            ->where("purchase_id = '" . pSQL($purchaseId) . "'");
        $id = (int) Db::getInstance()->getValue($sql, false);

        return $id ? new AvardaSession($id) : null;
    }

    /**
     * Returns active session for given cart, or creates new one
     *
     * @param Cart $cart
     * @param string $mode
     * @param bool $global
     *
     * @return AvardaSession
     *
     * @throws Exception
     */
    public static function getForCart(Cart $cart, $mode, $global)
    {
        static::markExpired();
        $cartId = (int) $cart->id;
        $customerId = (int) $cart->id_customer;
        $sql = (new DbQuery())
            ->select('id_session')
            ->from('avarda_session')
            ->where("id_cart = $cartId")
            ->where("id_customer = $customerId")
            ->where("status IN ('new', 'processing')")
            ->where('IFNULL(id_order, 0) = 0')
            ->where("mode = '" . pSQL($mode) . "'");
        $id = (int) Db::getInstance()->getValue($sql, false);
        if ($id) {
            $session = new AvardaSession($id);
            if (!Validate::isLoadedObject($session)) {
                throw new Exception('Avarda session not found');
            }
        } else {
            $session = new AvardaSession();
            $session->id_cart = $cartId;
            $session->id_customer = $customerId;
            $session->cart_signature = Utils::getCartInfoSignature(Utils::getCartInfo($cart));
            $session->status = 'new';
            $session->mode = $mode;
            $session->global = (int)((bool)$global);
            if (!$session->add()) {
                throw new Exception('Failed to create new avarda session');
            }
        }

        return $session;
    }

    /**
     * @throws Exception
     */
    public static function markExpired()
    {
        $threshold = (new DateTime())->sub(new DateInterval('PT' . static::SESSION_TIMEOUT . 'S'));
        $threshold = $threshold->format('Y-m-d H:i:s');
        Db::getInstance()->execute('UPDATE ' . _DB_PREFIX_ . "avarda_session SET `status` = 'expired' WHERE `status` IN ('new', 'processing') AND `date_add` < '$threshold'");
    }
}
