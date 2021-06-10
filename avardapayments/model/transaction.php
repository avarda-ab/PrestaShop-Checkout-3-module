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

class AvardaTransaction extends ObjectModel
{

    const TRANSACTION_AUTHORIZED = 'authorized';
    const TRANSACTION_DELIVERY = 'delivery';
    const TRANSACTION_REFUND = 'refund';
    const TRANSACTION_CANCEL = 'cancel';
    const TRANSACTION_RETURN = 'return';

    public static $definition = [
        'table' => 'avarda_transaction',
        'primary' => 'id_transaction',
        'fields' => [
            'id_order' => ['type' => self::TYPE_INT],
            'type' => ['type' => self::TYPE_STRING, 'required' => true, 'values' => [
                self::TRANSACTION_AUTHORIZED,
                self::TRANSACTION_DELIVERY,
                self::TRANSACTION_CANCEL,
                self::TRANSACTION_REFUND,
                self::TRANSACTION_RETURN
            ]],
            'amount' => ['type' => self::TYPE_STRING],
            'success' => ['type' => self::TYPE_BOOL],
            'error_message' => ['type' => self::TYPE_STRING],
            'date_add' => ['type' => self::TYPE_DATE],
        ],
    ];

    public $id_order;
    public $type;
    public $amount;
    public $success;
    public $error_message;
    public $date_add;

    /**
     * @param Order $order
     * @param boolean $all
     * @return PrestaShopCollection
     * @throws PrestaShopException
     */
    public static function getForOrder(Order $order, $all = false)
    {
        $collection = new PrestaShopCollection('AvardaTransaction');
        $collection->where('id_order', '=', (int)$order->id);
        if (! $all) {
            $collection->where('success', '=', 1);
        }
        $collection->orderBy('date_add');
        return $collection;
    }
}
