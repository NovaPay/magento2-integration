<?php
/**
 * Copyright © 2026 NovaPay. All rights reserved.
 * Licensed under the MIT License. See LICENSE for details.
 */
declare(strict_types=1);

namespace NovaPay\Payment\Plugin\Order;

use Magento\Sales\Model\Order\Payment;
use Magento\Sales\Model\Order;
use NovaPay\Payment\Gateway\Config;

/**
 * Hides Invoice, Ship and Hold on NovaPay orders that still await payment.
 *
 * A NovaPay order is placed in state `new` / status `novapay_pending` before the customer has paid.
 * Core offers Invoice, Ship and Hold on such an order, and an offline invoice there would record a
 * payment that never happened. Until NovaPay reports the money as held or taken (`holded`,
 * `hold_confirmed` or `paid`), `canInvoice()`, `canShip()` and `canHold()` return false for it.
 * `canCancel()` is deliberately left alone: an unpaid order must stay cancelable.
 *
 * The check reads the payment's in-memory `novapay_status`. The postback pipeline
 * ({@see \NovaPay\Payment\Model\OrderStatusApplier}) stores the incoming status on the payment
 * before it calls `canInvoice()` for a `paid` / `holded` / `hold_confirmed` event, so those calls
 * pass. Orders paid with any other method are never affected.
 */
class RestrictUnpaidActionsPlugin
{
    private const INFO_STATUS = 'novapay_status';

    /**
     * NovaPay session statuses after which the money is held or taken.
     */
    private const PAID_STATUSES = ['holded', 'hold_confirmed', 'paid'];

    /**
     * Blocks invoicing while the NovaPay payment is not held or taken.
     *
     * @param Order $subject
     * @param bool $result
     * @return bool
     */
    public function afterCanInvoice(Order $subject, bool $result): bool
    {
        return $result && !$this->awaitsNovaPayPayment($subject);
    }

    /**
     * Blocks shipping while the NovaPay payment is not held or taken.
     *
     * @param Order $subject
     * @param bool $result
     * @return bool
     */
    public function afterCanShip(Order $subject, bool $result): bool
    {
        return $result && !$this->awaitsNovaPayPayment($subject);
    }

    /**
     * Blocks putting the order on hold while the NovaPay payment is not held or taken.
     *
     * @param Order $subject
     * @param bool $result
     * @return bool
     */
    public function afterCanHold(Order $subject, bool $result): bool
    {
        return $result && !$this->awaitsNovaPayPayment($subject);
    }

    /**
     * Whether `$order` is a NovaPay order whose payment is not yet held or taken.
     *
     * @param Order $order
     * @return bool
     */
    private function awaitsNovaPayPayment(Order $order): bool
    {
        $payment = $order->getPayment();
        if (!$payment instanceof Payment || $payment->getMethod() !== Config::CODE) {
            return false;
        }

        $status = $payment->getAdditionalInformation(self::INFO_STATUS);

        return !in_array(is_string($status) ? $status : '', self::PAID_STATUSES, true);
    }
}
