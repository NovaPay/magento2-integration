<?php
/**
 * Copyright © 2026 NovaPay. All rights reserved.
 * Licensed under the MIT License. See LICENSE for details.
 */
declare(strict_types=1);

namespace NovaPay\Payment\Model\HoldConfirm;

use Magento\Framework\Phrase;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\Order\Payment;
use NovaPay\Payment\Gateway\Config;

/**
 * Decides what the optional "Confirm NovaPay Hold" order-view button does for an order (spec 7).
 *
 * The button exists only when the "NovaPay hold confirmation button in order view" option is on for
 * the order's store and the order was paid with NovaPay:
 *
 * - `holded`, the order can still be invoiced, no invoice exists yet and the hold is at most
 *   {@see MAX_HOLD_AGE_DAYS} days old: {@see MODE_CONFIRM}, the button opens the confirmation form;
 * - `holded` with an open (pending) invoice: the confirmation was sent and the `paid` /
 *   `hold_confirmed` postback has not arrived yet, {@see MODE_PENDING}, the form is read-only;
 * - everything else (other statuses, a canceled order, an expired hold): no button.
 *
 * The guards mirror, read-only, the ones {@see \NovaPay\Payment\Gateway\Command\CaptureCommand}
 * enforces when the invoice reaches NovaPay; the command stays the authority.
 */
class HoldConfirmAvailability
{
    public const MODE_CONFIRM = 'confirm';
    public const MODE_PENDING = 'pending';

    /**
     * NovaPay only allows a complete-hold call within 29 days of the hold (same as CaptureCommand).
     */
    public const MAX_HOLD_AGE_DAYS = 29;

    private const INFO_STATUS = 'novapay_status';

    /**
     * @param Config $config
     * @param DateTime $dateTime
     */
    public function __construct(
        private readonly Config $config,
        private readonly DateTime $dateTime
    ) {
    }

    /**
     * Whether the optional hold confirmation flow applies to this order (option on, NovaPay order).
     *
     * @param Order $order
     * @return bool
     */
    public function isFlowActive(Order $order): bool
    {
        $payment = $order->getPayment();
        if (!$payment instanceof Payment || $payment->getMethod() !== Config::CODE) {
            return false;
        }

        return $this->config->isHoldConfirmFlowEnabled((int)$order->getStoreId());
    }

    /**
     * What the button does for this order, or null when the button must not be shown.
     *
     * @param Order $order
     * @return string|null one of the MODE_* constants
     */
    public function getMode(Order $order): ?string
    {
        if (!$this->isFlowActive($order) || $this->getNovaPayStatus($order) !== 'holded') {
            return null;
        }

        if ($this->hasOpenInvoice($order)) {
            return self::MODE_PENDING;
        }

        if ($order->isCanceled()
            || !$order->canInvoice()
            || $this->getInvoiceStates($order) !== []
            || !$this->isHoldWithinAgeLimit($order)
        ) {
            return null;
        }

        return self::MODE_CONFIRM;
    }

    /**
     * The held amount: the order total in the base currency.
     *
     * @param Order $order
     * @return float
     */
    public function getHoldAmount(Order $order): float
    {
        return round((float)$order->getBaseGrandTotal(), 2);
    }

    /**
     * Whether a partial confirmation can be sent (it requires the Recipient Identifier).
     *
     * @param Order $order
     * @return bool
     */
    public function isPartialAllowed(Order $order): bool
    {
        return $this->getRecipientIdentifier($order) !== '';
    }

    /**
     * The configured Recipient Identifier (ЄДРПОУ/ІПН) of the order's store, '' when not set.
     *
     * @param Order $order
     * @return string
     */
    public function getRecipientIdentifier(Order $order): string
    {
        return trim($this->config->getRecipientIdentifier((int)$order->getStoreId()));
    }

    /**
     * Notice of a confirmation sent to NovaPay whose postback has not arrived yet, or null.
     *
     * @param Order $order
     * @return Phrase|null
     */
    public function getPendingNotice(Order $order): ?Phrase
    {
        if ($this->getNovaPayStatus($order) === 'holded' && $this->hasOpenInvoice($order)) {
            return __('The hold confirmation was submitted, waiting for NovaPay confirmation.');
        }

        return null;
    }

    /**
     * Whether the hold, dated by the authorization transaction, is at most {@see MAX_HOLD_AGE_DAYS} old.
     *
     * An order without a readable authorization date is treated as not confirmable, like CaptureCommand.
     *
     * @param Order $order
     * @return bool
     */
    public function isHoldWithinAgeLimit(Order $order): bool
    {
        $payment = $order->getPayment();
        if (!$payment instanceof Payment) {
            return false;
        }

        $authTransaction = $payment->getAuthorizationTransaction();
        if (!$authTransaction) {
            return false;
        }

        $heldAt = $this->dateTime->gmtTimestamp((string)$authTransaction->getCreatedAt());
        if ($heldAt === false) {
            return false;
        }

        return ($this->dateTime->gmtTimestamp() - $heldAt) / 86400 <= self::MAX_HOLD_AGE_DAYS;
    }

    /**
     * The stored NovaPay session status of the order.
     *
     * @param Order $order
     * @return string
     */
    public function getNovaPayStatus(Order $order): string
    {
        $payment = $order->getPayment();
        if (!$payment instanceof Payment) {
            return '';
        }

        $value = $payment->getAdditionalInformation(self::INFO_STATUS);

        return is_scalar($value) ? trim((string)$value) : '';
    }

    /**
     * Whether the order has an open (pending) invoice.
     *
     * @param Order $order
     * @return bool
     */
    private function hasOpenInvoice(Order $order): bool
    {
        return in_array(Invoice::STATE_OPEN, $this->getInvoiceStates($order), true);
    }

    /**
     * The states of the order's saved invoices.
     *
     * @param Order $order
     * @return int[]
     */
    private function getInvoiceStates(Order $order): array
    {
        $states = [];
        foreach ($order->getInvoiceCollection() as $invoice) {
            $states[] = (int)$invoice->getState();
        }

        return $states;
    }
}
