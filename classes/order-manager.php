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

use AvardaSession;
use AvardaTransaction;
use Cart;
use Exception;
use Order;
use PrestaShopException;

class OrderManager
{
    /** @var Api */
    private $api;

    /** @var string last error */
    private $error;

    const STATUS_NONE = 0;
    const STATUS_AUTHORIZED = 1;
    const STATUS_DELIVERED = 2;
    const STATUS_CANCELED = 3;

    /**
     * OrderManager constructor.
     * @param Api $api
     */
    public function __construct(Api $api)
    {
        $this->api = $api;
    }

    /**
     * @param Order $order
     * @return bool
     * @throws PrestaShopException
     */
    public function isAvardaOrder(Order $order)
    {
        return $this->getOrderStatus($order) !== self::STATUS_NONE;
    }

    /**
     * @param Order $order
     * @param $amount
     * @return bool
     * @throws PrestaShopException
     */
    public function authorize(Order $order, $amount)
    {
        if ($this->getOrderStatus($order) === self::STATUS_NONE) {
            return $this->executeTransaction(
                true,
                AvardaTransaction::TRANSACTION_AUTHORIZED,
                $amount,
                $order
            );
        }
        return false;
    }

    /**
     * @param Order $order
     * @return boolean
     * @throws Exception
     */
    public function delivery(Order $order)
    {
        if ($this->canDeliver($order)) {
            return $this->executeTransaction(
                function ($purchaseId, Order $order) {
                    return $this->api->createPurchaseOrder($order->reference, $purchaseId, $this->getOrderItems($order), $order->getWsShippingNumber());
                },
                AvardaTransaction::TRANSACTION_DELIVERY,
                $order->total_paid_tax_incl,
                $order
            );
        }
        return false;
    }

    /**
     * @param Order $order
     * @param double $amount
     * @return boolean
     * @throws PrestaShopException
     */
    public function refund(Order $order, $amount = null)
    {
        if ($this->canRefund($order)) {
            return $this->executeTransaction(
                function ($purchaseId, Order $order) use ($amount) {
                    return is_null($amount)
                        ? $this->api->refundRemaining($order->reference, $purchaseId)
                        : $this->api->refundAmount($order->reference, $purchaseId, $amount);
                },
                AvardaTransaction::TRANSACTION_REFUND,
                is_null($amount) ? $this->getRemainingBalance($order) : $amount,
                $order
            );
        }
        return false;
    }

    /**
     * @param Order $order
     * @param double $amount
     * @param $reason
     * @return boolean
     */
    public function returnItem(Order $order, $amount, $reason)
    {
        return $this->returnItems($order, [
            [
                'Amount' => $amount,
                'Description' => $reason,
            ]
        ]);
    }

    /**
     * @param Order $order
     * @param array $items
     * @return boolean
     */
    public function returnItems(Order $order, $items)
    {
        if ($this->canReturn($order)) {
            $amount = array_reduce($items, function($total, $item) {
                return $total + $item['Amount'];
            }, 0.0);
            $amount = Utils::roundPrice($amount);
            return $this->executeTransaction(
                function ($purchaseId, Order $order) use ($items) {
                    return $this->api->returnItems($order->reference, $purchaseId, $items);
                },
                AvardaTransaction::TRANSACTION_RETURN,
                $amount,
                $order
            );
        }
        return false;
    }

    /**
     * @param Order $order
     * @param string $reason
     * @return bool
     * @throws PrestaShopException
     */
    public function cancelPayment(Order $order, $reason = null)
    {
        if ($this->canCancel($order)) {
            return $this->executeTransaction(
                function ($purchaseId) use ($reason) {
                    if (!$reason) {
                        $reason = 'I do not want this item anymore';
                    }
                    return $this->api->cancelPayment($purchaseId, $reason);
                },
                AvardaTransaction::TRANSACTION_CANCEL,
                $this->getRemainingBalance($order),
                $order
            );
        }
        return false;
    }

    /**
     * @param Callable|boolean $callable
     * @param string $type
     * @param double $amount
     * @param Order $order
     * @return bool
     */
    private function executeTransaction($callable, $type, $amount, Order $order)
    {
        $this->error = null;
        try {
            $purchaseId = $this->getPurchaseId($order);
            if (is_callable($callable)) {
                call_user_func($callable, $purchaseId, $order);
                    // This is a callback for executable functions 
             }
        } catch (Exception $e) {
            $this->error = $e instanceof AvardaException ? $e->getMessage() : $e->__toString();
        }
        $transaction = new AvardaTransaction();
        $transaction->id_order = (int)$order->id;
        $transaction->type = $type;
        $transaction->amount = $amount;
        $transaction->success = !$this->error;
        $transaction->error_message = $this->error;
        $transaction->save();
        return $transaction->success;
    }


    /**
     * @param Order $order
     * @return mixed
     * @throws Exception
     */
    public function getOrderItems(Order $order)
    { 
        $items = array_map(function ($row) {
            return [
                'description' => Utils::maxChars($row['product_name'], 35),
                'amount' => Utils::roundPrice($row['total_price_tax_incl']),
                'taxAmount' => Utils::roundPrice($row['total_price_tax_incl']) - Utils::roundPrice($row['total_price_tax_excl']),
                'quantity' => (int) $row['product_quantity'] 
            ];
        }, $order->getProductsDetail());
        
        if ($order->total_discounts_tax_incl !== 0) {
            $items[] = [
                'description' => 'Discount',
                'amount' => Utils::roundPrice(-1 * $order->total_discounts),
                'taxAmount' => Utils::roundPrice(-1 * $order->total_discounts_tax_incl) - Utils::roundPrice(-1 * $order->total_discounts_tax_excl)
            ];
        }

        if ($order->total_shipping_tax_incl > 0) {
            $items[] = [
                'description' => 'Shipping',
                'amount' => Utils::roundPrice($order->total_shipping_tax_incl),
                'taxAmount' => 0,
                'quantity' => 1,
            ];
        }
        
        if ($order->total_wrapping_tax_incl > 0) {
            $items[] = [
                'description' => 'Wrapping',
                'amount' => Utils::roundPrice($order->total_wrapping_tax_incl),
                'taxAmount' => Utils::roundPrice($order->total_wrapping_tax_incl) - Utils::roundPrice($order->total_wrapping_tax_excl),
                'quantity' => 1
            ];
        }
        
        $total = $order->total_paid_tax_incl;
        $totalLines = array_reduce($items, function($sum, $item) {
            return $sum + $item['amount'];
        }, 0.0);
        $diff = Utils::roundPrice($total - $totalLines);
        if (abs($diff) > 0) {
            $items[] = [
                'description' => 'adjustment',
                'amount' => $diff,
                'taxAmount' => 0,
                'quantity' => 1
            ];
        }
        
        return $items;
    }

    /**
     * @param Order $order
     * @return string
     * @throws AvardaException
     */
    private function getPurchaseId(Order $order)
    {
        $session = AvardaSession::getForOrder($order);
        if (!$session) {
            throw new AvardaException("No avarda session associated with order " . (int)$order->id);
        }
        return $session->purchase_id;
    }

    public function canDeliver($order)
    {
        return $this->getOrderStatus($order) === self::STATUS_AUTHORIZED;
    }

    public function canCancel($order)
    {
        return $this->getOrderStatus($order) === self::STATUS_AUTHORIZED;
    }

    public function canRefund($order)
    {
        return $this->getOrderStatus($order) === self::STATUS_AUTHORIZED;
    }

    public function canReturn($order)
    {
        return $this->getOrderStatus($order) === self::STATUS_DELIVERED;
    }

    /**
     * @param Order $order
     * @return double
     * @throws PrestaShopException
     */
    public function getAuthorizedBalance(Order $order)
    {
        return $this->sumAmount($order, AvardaTransaction::TRANSACTION_AUTHORIZED);
    }

    /**
     * @param Order $order
     * @return float
     * @throws PrestaShopException
     */
    public function getCapturedBalance(Order $order)
    {
        return $this->sumAmount($order, AvardaTransaction::TRANSACTION_DELIVERY);
    }

    /**
     * @param Order $order
     * @return float
     * @throws PrestaShopException
     */
    public function getReturnedBalance(Order $order)
    {
        return $this->sumAmount($order, AvardaTransaction::TRANSACTION_RETURN);
    }

    /**
     * @param Order $order
     * @return float
     * @throws PrestaShopException
     */
    public function getCanceledBalance(Order $order)
    {
        return $this->sumAmount($order, AvardaTransaction::TRANSACTION_CANCEL);
    }

    /**
     * @param Order $order
     * @param $type
     * @return float
     * @throws PrestaShopException
     */
    public function sumAmount(Order $order, $type)
    {
        /** @var AvardaTransaction[] $transactions */
        $transactions = AvardaTransaction::getForOrder($order);
        $ret = 0.0;
        foreach ($transactions as $transaction) {
            if ($transaction->type === $type) {
                $ret += $transaction->amount;
            }
        }
        return $ret;
    }

    /**
     * @param Order $order
     * @return int
     * @throws PrestaShopException
     */
    public function getOrderStatus(Order $order)
    {
        /** @var AvardaTransaction[] $transactions */
        $transactions = AvardaTransaction::getForOrder($order);
        $status = self::STATUS_NONE;
        foreach ($transactions as $transaction) {
            switch ($transaction->type) {
                case AvardaTransaction::TRANSACTION_AUTHORIZED:
                    $status = max($status, static::STATUS_AUTHORIZED);
                    break;
                case AvardaTransaction::TRANSACTION_CANCEL:
                    $status = max($status, static::STATUS_CANCELED);
                    break;
                case AvardaTransaction::TRANSACTION_DELIVERY:
                    $status = max($status, static::STATUS_DELIVERED);
                    break;
            }
        }
        return $status;
    }

    /**
     * @param Order $order
     * @return float
     * @throws PrestaShopException
     */
    public function getRemainingBalance(Order $order)
    {
        $balance = 0.0;
        /** @var AvardaTransaction[] $transactions */
        $transactions = AvardaTransaction::getForOrder($order);
        foreach ($transactions as $transaction) {
            switch ($transaction->type) {
                case AvardaTransaction::TRANSACTION_AUTHORIZED:
                    $balance += $transaction->amount;
                    break;
                case AvardaTransaction::TRANSACTION_CANCEL:
                    return 0.0;
                case AvardaTransaction::TRANSACTION_DELIVERY:
                    $balance -= $transaction->amount;
                    break;
                case AvardaTransaction::TRANSACTION_REFUND:
                    $balance -= $transaction->amount;
                    break;
                case AvardaTransaction::TRANSACTION_RETURN:
                    $balance -= $transaction->amount;
                    break;
            }
        }
        return $balance;
    }

    public function getLastError()
    {
        return $this->error;
    }


}