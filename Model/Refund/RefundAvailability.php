<?php
/**
 * Copyright © 2026 NovaPay. All rights reserved.
 * Licensed under the MIT License. See LICENSE for details.
 */
declare(strict_types=1);

namespace NovaPay\Payment\Model\Refund;

use Magento\Framework\Phrase;
use Magento\Framework\Serialize\Serializer\Json as JsonSerializer;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use NovaPay\Payment\Gateway\Config;

/**
 * Decides what the optional "NovaPay Refund" order-view button does for an order.
 *
 * The button exists only when the "NovaPay refund button in order view" option is on for the
 * order's store and the order was paid with NovaPay. It follows the spec matrix
 * "payment status => merchant actions":
 *
 * - `holded` with an open hold (the order can still be canceled): {@see MODE_CANCEL_HOLD}, the button
 *   cancels the order through the core order management service, which releases the hold at NovaPay.
 * - `holded` on an order already canceled (the hold release was sent, the `voided` postback has not
 *   arrived yet): {@see MODE_PENDING}, the button opens the form read-only with a notice.
 * - `paid` / `hold_confirmed`: {@see MODE_REFUND}, the button opens the refund form, which creates a
 *   standard online credit memo. The button stays after a refund until the `voided` postback changes
 *   the status (spec 8.4); when nothing is left to refund the form is read-only
 *   ({@see getRefundBlockReason()}).
 * - every other status (created, processing, failed, expired, voided): no button.
 *
 * The refund guards below mirror, read-only, the ones {@see \NovaPay\Payment\Gateway\Command\RefundCommand}
 * enforces when the credit memo reaches NovaPay. They only let the refund form explain in advance
 * why a refund cannot be made; the command stays the authority.
 */
class RefundAvailability
{
    public const MODE_CANCEL_HOLD = 'cancel_hold';
    public const MODE_REFUND = 'refund';
    public const MODE_PENDING = 'pending';

    private const INFO_STATUS = 'novapay_status';
    private const INFO_SESSION_ID = 'novapay_session_id';
    private const INFO_REFUNDED_OPERATIONS = 'novapay_refunded_operations';
    private const REFUNDABLE_STATUSES = ['paid', 'hold_confirmed'];
    private const AMOUNT_TOLERANCE = 0.01;

    /**
     * @param Config $config
     * @param JsonSerializer $json
     */
    public function __construct(
        private readonly Config $config,
        private readonly JsonSerializer $json
    ) {
    }

    /**
     * Whether the optional refund flow applies to this order (option on, order paid with NovaPay).
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

        return $this->config->isRefundFlowEnabled((int)$order->getStoreId());
    }

    /**
     * What the button does for this order, or null when the button must not be shown.
     *
     * @param Order $order
     * @return string|null one of the MODE_* constants
     */
    public function getMode(Order $order): ?string
    {
        if (!$this->isFlowActive($order)) {
            return null;
        }

        $status = $this->getNovaPayStatus($order);
        if ($status === 'holded') {
            if ($order->canCancel()) {
                return self::MODE_CANCEL_HOLD;
            }

            return $order->isCanceled() ? self::MODE_PENDING : null;
        }

        return in_array($status, self::REFUNDABLE_STATUSES, true) ? self::MODE_REFUND : null;
    }

    /**
     * The notice of an operation sent to NovaPay whose postback has not arrived yet, or null.
     *
     * - a released hold (`holded`, order canceled) waits for the `voided` postback;
     * - a full refund (`paid` / `hold_confirmed`, everything paid is refunded) waits for it too.
     *
     * @param Order $order
     * @return Phrase|null
     */
    public function getPendingNotice(Order $order): ?Phrase
    {
        $status = $this->getNovaPayStatus($order);
        if ($status === 'holded' && $order->isCanceled()) {
            return __('The hold release was submitted, waiting for NovaPay confirmation.');
        }

        if (in_array($status, self::REFUNDABLE_STATUSES, true) && $this->isFullyRefunded($order)) {
            return __('The refund was submitted, waiting for NovaPay confirmation.');
        }

        return null;
    }

    /**
     * Why no NovaPay refund can be made for this order at all, or null when a refund is possible.
     *
     * @param Order $order
     * @return Phrase|null
     */
    public function getRefundBlockReason(Order $order): ?Phrase
    {
        $pendingNotice = $this->getPendingNotice($order);
        if ($pendingNotice !== null) {
            return $pendingNotice;
        }

        if (!in_array($this->getNovaPayStatus($order), self::REFUNDABLE_STATUSES, true)) {
            return __('This order was not confirmed paid via NovaPay; use an offline credit memo instead.');
        }

        if ($this->getPaymentInfo($order, self::INFO_SESSION_ID) === '') {
            return __('This order has no NovaPay session to refund.');
        }

        if ($this->hasPriorRefund($order)) {
            return __(
                'NovaPay already processed a refund for this payment; further refunds must be '
                . 'handled through NovaPay support.'
            );
        }

        if (!$order->canCreditmemo()) {
            return __('This order cannot be refunded in its current state.');
        }

        return null;
    }

    /**
     * Why a partial refund (selected quantities) is not possible, or null when it is.
     *
     * A payment that was itself only partially captured can only be refunded in full.
     *
     * @param Order $order
     * @return Phrase|null
     */
    public function getPartialRefundBlockReason(Order $order): ?Phrase
    {
        $notCaptured = (float)$order->getBaseGrandTotal() - (float)$order->getBaseTotalPaid();
        if ($notCaptured > self::AMOUNT_TOLERANCE) {
            return __(
                'Partial refunds are not available for a partially captured payment; refund the '
                . 'full captured amount instead.'
            );
        }

        return null;
    }

    /**
     * Whether Magento or NovaPay already recorded a refund for this order.
     *
     * @param Order $order
     * @return bool
     */
    public function hasPriorRefund(Order $order): bool
    {
        if ((float)$order->getBaseTotalRefunded() > self::AMOUNT_TOLERANCE) {
            return true;
        }

        $stored = $this->getPaymentInfo($order, self::INFO_REFUNDED_OPERATIONS);
        if ($stored === '') {
            return false;
        }

        try {
            $decoded = $this->json->unserialize($stored);
        } catch (\InvalidArgumentException) {
            return false;
        }

        return is_array($decoded) && $decoded !== [];
    }

    /**
     * The stored NovaPay session status of the order.
     *
     * @param Order $order
     * @return string
     */
    public function getNovaPayStatus(Order $order): string
    {
        return $this->getPaymentInfo($order, self::INFO_STATUS);
    }

    /**
     * A trimmed string value from the payment's additional information, '' when missing.
     *
     * @param Order $order
     * @param string $key
     * @return string
     */
    private function getPaymentInfo(Order $order, string $key): string
    {
        $payment = $order->getPayment();
        if (!$payment instanceof Payment) {
            return '';
        }

        $value = $payment->getAdditionalInformation($key);

        return is_scalar($value) ? trim((string)$value) : '';
    }

    /**
     * Whether Magento recorded refunds for the whole paid amount.
     *
     * @param Order $order
     * @return bool
     */
    private function isFullyRefunded(Order $order): bool
    {
        $refunded = (float)$order->getBaseTotalRefunded();

        return $refunded > self::AMOUNT_TOLERANCE
            && (float)$order->getBaseTotalPaid() - $refunded <= self::AMOUNT_TOLERANCE;
    }
}
