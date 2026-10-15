<?php
/**
 * Copyright © 2026 NovaPay. All rights reserved.
 * Licensed under the MIT License. See LICENSE for details.
 */
declare(strict_types=1);

namespace NovaPay\Payment\Gateway\Command;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Serialize\Serializer\Json as JsonSerializer;
use Magento\Payment\Gateway\CommandInterface;
use Magento\Payment\Gateway\Helper\SubjectReader;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Creditmemo;
use Magento\Sales\Model\Order\Payment;
use NovaPay\Payment\Gateway\ErrorMapper;
use NovaPay\Payment\Gateway\Http\Client;
use NovaPay\Payment\Gateway\Http\Exception\ApiException;
use NovaPay\Payment\Gateway\Request\VoidBuilder;
use NovaPay\Payment\Model\Order\GatewayFailureNote;
use NovaPay\Payment\Model\Order\GatewayFailureNoteRegistry;
use Psr\Log\LoggerInterface;

/**
 * "refund" gateway command — `POST /v1/void` used as an **online credit memo refund**. Invoked only
 * by Magento's native online credit memo flow ("Refund Online" on a paid order) — never a custom
 * button.
 *
 * This integration only ever makes one `/v1/payment` call per order, so the NovaPay numeric
 * operation id ({@see INFO_PAYMENT_ID}, or the fresher {@see INFO_CAPTURE_OPERATION_ID} when a
 * partial complete-hold has since superseded it — see {@see requireOperationId()}) is a single,
 * order-wide value, never one-per-Magento-line; `operations[]` therefore always has at most one
 * element, built from this credit memo's own aggregate amount.
 *
 * Business rules enforced here:
 * - the order's stored `novapay_status` must be `paid` or `hold_confirmed`.
 * - adjustment fees/refunds are never supported — any non-zero adjustment on the credit memo blocks
 *   the whole refund.
 * - **only one NovaPay refund per order is currently supported**: NovaPay has not confirmed
 *   whether a second `/v1/void` after a partial refund refunds the remainder, double-refunds the
 *   already-returned part, or is rejected outright — until that is confirmed, any refund attempt
 *   on an order that already shows a prior NovaPay refund — via Magento's own
 *   `base_total_refunded` ledger or via the NovaPay-confirmed `novapay_refunded_operations` list
 *   ({@see \NovaPay\Payment\Model\OrderStatusApplier::applyPartialVoid()}) — is refused outright,
 *   because a second `operations[]` refund would reuse the same, already consumed NovaPay
 *   operation id and be rejected as already refunded. See {@see assertNoPriorRefund()} below.
 * - **a PARTIAL refund on a partially captured payment is not currently supported**: until
 *   NovaPay live-verifies the semantics, refusing a partial refund whenever
 *   `base_total_paid < base_grand_total` (a partial complete-hold auto-released the rest of the
 *   hold) — see {@see assertNotPartiallyCapturedPayment()}. A full refund of the actually-captured
 *   amount is unaffected.
 * - shipping is refundable only as part of a full refund.
 * - the credit memo's amount must not exceed the order's remaining refundable balance.
 *
 * Order state is never written here: the order stays `paid`/`hold_confirmed` until the
 * session-level `voided` postback arrives — see
 * {@see \NovaPay\Payment\Model\OrderStatusApplier::applyVoided()} for the interplay with Magento's
 * own order-state auto-correction on order save.
 *
 * @api
 */
class RefundCommand implements CommandInterface
{
    private const INFO_STATUS = 'novapay_status';
    private const INFO_SESSION_ID = 'novapay_session_id';
    private const INFO_PAYMENT_ID = 'novapay_payment_id';

    /**
     * The `operations[0].transaction_id` most recently persisted by
     * {@see \NovaPay\Payment\Model\OrderStatusApplier::persistCaptureOperationId()} from a `paid`/
     * `hold_confirmed` event. After a partial complete-hold, NovaPay issues a NEW transaction_id
     * for the operation — {@see INFO_PAYMENT_ID} (set once at checkout, never updated) would then be
     * stale and rejected by NovaPay for a subsequent partial refund. {@see requireOperationId()}
     * prefers this field when present, falling back to {@see INFO_PAYMENT_ID} otherwise.
     */
    private const INFO_CAPTURE_OPERATION_ID = 'novapay_capture_operation_id';

    /**
     * The NovaPay-confirmed partial-refund ledger {@see \NovaPay\Payment\Model\OrderStatusApplier}
     * maintains (mirrored here read-only).
     */
    private const INFO_REFUNDED_OPERATIONS = 'novapay_refunded_operations';

    /**
     * Float-rounding tolerance for every balance/amount comparison below.
     */
    private const AMOUNT_TOLERANCE = 0.01;

    /**
     * @param VoidBuilder $requestBuilder
     * @param Client $client
     * @param ErrorMapper $errorMapper
     * @param LoggerInterface $logger
     * @param JsonSerializer $json
     * @param GatewayFailureNoteRegistry $failureNotes
     */
    public function __construct(
        private readonly VoidBuilder $requestBuilder,
        private readonly Client $client,
        private readonly ErrorMapper $errorMapper,
        private readonly LoggerInterface $logger,
        private readonly JsonSerializer $json,
        private readonly GatewayFailureNoteRegistry $failureNotes
    ) {
    }

    /**
     * @inheritDoc
     *
     * @throws LocalizedException every guard below, and any mapped NovaPay API error
     */
    public function execute(array $commandSubject)
    {
        $paymentDO = SubjectReader::readPayment($commandSubject);
        $amount = (float)SubjectReader::readAmount($commandSubject);

        /** @var Payment $payment */
        $payment = $paymentDO->getPayment();
        $order = $payment->getOrder();
        $storeId = (int)$order->getStoreId();

        $this->assertPaidViaNovaPay($payment, $order);

        $creditmemo = $this->readCreditmemo($payment);
        $this->assertNoAdjustments($creditmemo);
        $this->assertNoPriorRefund($order, $payment, $amount);

        $isFullRefund = $this->isFullRefund($order);
        if (!$isFullRefund) {
            $this->assertNotPartiallyCapturedPayment($order);
        }
        $this->assertShippingOnlyOnFullRefund($creditmemo, $isFullRefund);
        $this->assertWithinRefundableBalance($order);

        $sessionId = trim((string)$payment->getAdditionalInformation(self::INFO_SESSION_ID));
        if ($sessionId === '') {
            throw new LocalizedException(__('This order has no NovaPay session to refund.'));
        }

        $buildSubject = ['session_id' => $sessionId, 'store_id' => $storeId];
        if (!$isFullRefund) {
            $buildSubject['operations'] = [
                [
                    'id' => $this->requireOperationId($payment),
                    'refund_amount' => round($amount, 2),
                ],
            ];
        }

        $payload = $this->requestBuilder->build($buildSubject);

        try {
            $this->client->request('/v1/void', $payload, $storeId);
        } catch (ApiException $e) {
            $this->logger->error('NovaPay void (refund) call failed.', [
                'event' => 'command.refund_error',
                'order_increment_id' => $order->getIncrementId(),
                'endpoint' => $e->getEndpoint(),
                'http_status' => $e->getHttpStatusCode(),
            ]);

            $error = $this->errorMapper->map($e);
            // Written to the order history by the admin controller after the rolled-back transaction.
            $this->failureNotes->add(new GatewayFailureNote(
                (int)$order->getEntityId(),
                GatewayFailureNote::OPERATION_REFUND,
                $amount,
                (string)$order->getBaseCurrencyCode(),
                $error
            ));

            throw $error;
        }

        return null;
    }

    /**
     * The order's stored `novapay_status` must be `paid` or `hold_confirmed`.
     *
     * @param Payment $payment
     * @param Order $order
     * @return void
     * @throws LocalizedException
     */
    private function assertPaidViaNovaPay(Payment $payment, Order $order): void
    {
        $status = trim((string)$payment->getAdditionalInformation(self::INFO_STATUS));
        if (!in_array($status, ['paid', 'hold_confirmed'], true)) {
            $this->logger->warning(
                'NovaPay refund command reached for an order whose recorded novapay_status is '
                . 'neither "paid" nor "hold_confirmed"; refusing to call NovaPay.',
                [
                    'event' => 'command.refund_refused_wrong_status',
                    'order_increment_id' => $order->getIncrementId(),
                    'novapay_status' => $status,
                ]
            );

            throw new LocalizedException(
                __('This order was not confirmed paid via NovaPay; use an offline credit memo instead.')
            );
        }
    }

    /**
     * The credit memo `Payment::refund()` attached to `$payment` before calling this command.
     *
     * @param Payment $payment
     * @return Creditmemo
     * @throws LocalizedException
     */
    private function readCreditmemo(Payment $payment): Creditmemo
    {
        $creditmemo = $payment->getCreditmemo();
        if (!$creditmemo instanceof Creditmemo) {
            throw new LocalizedException(
                __('Unable to determine the credit memo details for this NovaPay refund.')
            );
        }

        return $creditmemo;
    }

    /**
     * Adjustment fees/refunds are never supported.
     *
     * @param Creditmemo $creditmemo
     * @return void
     * @throws LocalizedException
     */
    private function assertNoAdjustments(Creditmemo $creditmemo): void
    {
        $adjustmentPositive = abs((float)$creditmemo->getBaseAdjustmentPositive());
        $adjustmentNegative = abs((float)$creditmemo->getBaseAdjustmentNegative());

        if ($adjustmentPositive > self::AMOUNT_TOLERANCE || $adjustmentNegative > self::AMOUNT_TOLERANCE) {
            throw new LocalizedException(
                __(
                    'NovaPay refunds do not support adjustment fees or refunds. Remove them from '
                    . 'the credit memo and try again.'
                )
            );
        }
    }

    /**
     * Refuses any refund attempt on an order that already has a prior NovaPay refund on record.
     *
     * Checked by either signal:
     *
     * 1. Magento's own `base_total_refunded` ledger, read *before* this credit memo's own amount
     *    (`RefundOperation::execute()` has already added it in — see the class docblock) —
     *    `base_total_refunded - $amount > 0` means a previous credit memo already contributed to
     *    the total.
     * 2. The NovaPay-confirmed `novapay_refunded_operations` list
     *    ({@see \NovaPay\Payment\Model\OrderStatusApplier::applyPartialVoid()}) is non-empty — this
     *    integration only ever records one NovaPay operation id per order, so any entry at all
     *    means that id has already been refunded once at NovaPay's side.
     *
     * Once NovaPay confirms whether a bare `/v1/void` after a partial refund refunds the remainder
     * or double-refunds it, this guard can be relaxed to actually support a second/remainder
     * refund instead of refusing it outright.
     *
     * @param Order $order
     * @param Payment $payment
     * @param float $amount this credit memo's own base amount (already included in
     *     `$order->getBaseTotalRefunded()` by the time this command runs)
     * @return void
     * @throws LocalizedException
     */
    private function assertNoPriorRefund(Order $order, Payment $payment, float $amount): void
    {
        $priorRefundedBeforeThisMemo = round((float)$order->getBaseTotalRefunded() - $amount, 2);
        if ($priorRefundedBeforeThisMemo > self::AMOUNT_TOLERANCE) {
            $this->refuseSecondRefund(
                $order,
                'base_total_refunded already reflects a refund recorded before this credit memo',
                ['prior_refunded' => $priorRefundedBeforeThisMemo]
            );
        }

        if ($this->decodeRefundedOperationsLedger($payment) !== []) {
            $this->refuseSecondRefund(
                $order,
                'the NovaPay-confirmed novapay_refunded_operations ledger already has an entry',
                []
            );
        }
    }

    /**
     * Logs a WARNING and throws the standard "already has a prior refund" exception.
     *
     * @param Order $order
     * @param string $reason
     * @param array $extraContext
     * @return void
     * @throws LocalizedException
     */
    private function refuseSecondRefund(Order $order, string $reason, array $extraContext): void
    {
        $this->logger->warning(
            'NovaPay refund command reached for an order that already has a prior NovaPay refund '
            . 'on record (' . $reason . '); refusing a second refund attempt until NovaPay confirms '
            . 'the semantics of a repeat refund on the same session.',
            [
                'event' => 'command.refund_refused_second_refund',
                'order_increment_id' => $order->getIncrementId(),
            ] + $extraContext
        );

        throw new LocalizedException(
            __(
                'NovaPay already processed a refund for this payment; further refunds must be '
                . 'handled through NovaPay support.'
            )
        );
    }

    /**
     * Decode the NovaPay-confirmed `novapay_refunded_operations` list, never throwing.
     *
     * @param Payment $payment
     * @return array
     */
    private function decodeRefundedOperationsLedger(Payment $payment): array
    {
        $stored = $payment->getAdditionalInformation(self::INFO_REFUNDED_OPERATIONS);
        if (!is_string($stored) || $stored === '') {
            return [];
        }

        try {
            $decoded = $this->json->unserialize($stored);
        } catch (\InvalidArgumentException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Whether this credit memo exhausts the order's entire remaining refundable balance.
     *
     * `RefundOperation::execute()` (native Magento, the only caller that ever reaches this command)
     * has already added this credit memo's own amount into `$order`'s `base_total_refunded` before
     * calling the gateway — see the class docblock — so "nothing left to refund after this memo" is
     * exactly `base_total_paid - base_total_refunded == 0` at this point. Adjustments are blocked
     * above, so there is no way to reach that balance without having actually refunded every item
     * and any shipping in full; no separate per-item qty check is needed.
     *
     * @param Order $order
     * @return bool
     */
    private function isFullRefund(Order $order): bool
    {
        $remainingAfter = (float)$order->getBaseTotalPaid() - (float)$order->getBaseTotalRefunded();

        return abs($remainingAfter) <= self::AMOUNT_TOLERANCE;
    }

    /**
     * Until NovaPay live-verifies the semantics, a PARTIAL refund (the `operations[]` path) is
     * refused outright when the order's own payment was itself only partially captured
     * (`base_total_paid < base_grand_total`, e.g. a partial complete-hold that auto-released the
     * rest of the hold — see
     * {@see \NovaPay\Payment\Gateway\Request\CompleteHoldBuilder}'s class docblock). Refunding a
     * further slice of an already-partial capture is untested territory; a full refund of the
     * actually-captured amount remains available and is not affected by this guard.
     *
     * @param Order $order
     * @return void
     * @throws LocalizedException
     */
    private function assertNotPartiallyCapturedPayment(Order $order): void
    {
        $baseTotalPaid = (float)$order->getBaseTotalPaid();
        $baseGrandTotal = (float)$order->getBaseGrandTotal();

        if ($baseGrandTotal - $baseTotalPaid > self::AMOUNT_TOLERANCE) {
            $this->logger->warning(
                'NovaPay refund command reached for a PARTIAL refund on an order whose payment was '
                . 'itself only partially captured (base_total_paid < base_grand_total); refusing '
                . 'until NovaPay confirms partial-refund semantics on a partially captured payment.',
                [
                    'event' => 'command.refund_refused_partial_capture',
                    'order_increment_id' => $order->getIncrementId(),
                    'base_total_paid' => $baseTotalPaid,
                    'base_grand_total' => $baseGrandTotal,
                ]
            );

            throw new LocalizedException(
                __(
                    'Partial refunds are not available for a partially captured payment; refund the '
                    . 'full captured amount instead.'
                )
            );
        }
    }

    /**
     * Shipping is refundable only as part of a full refund.
     *
     * @param Creditmemo $creditmemo
     * @param bool $isFullRefund
     * @return void
     * @throws LocalizedException
     */
    private function assertShippingOnlyOnFullRefund(Creditmemo $creditmemo, bool $isFullRefund): void
    {
        if ($isFullRefund) {
            return;
        }

        if (abs((float)$creditmemo->getBaseShippingAmount()) > self::AMOUNT_TOLERANCE) {
            throw new LocalizedException(
                __('Shipping can only be refunded as part of a full refund.')
            );
        }
    }

    /**
     * Defense-in-depth: the credit memo(s) recorded so far must never exceed what was actually
     * paid. Magento's own creditmemo validators already prevent this at the item/qty level before
     * this command is ever reached; this is a second, explicit assertion of the same invariant
     * against the order's own totals.
     *
     * @param Order $order
     * @return void
     * @throws LocalizedException
     */
    private function assertWithinRefundableBalance(Order $order): void
    {
        if ((float)$order->getBaseTotalRefunded() > (float)$order->getBaseTotalPaid() + self::AMOUNT_TOLERANCE) {
            throw new LocalizedException(
                __('The refund amount exceeds the amount paid for this order.')
            );
        }
    }

    /**
     * The operation id required for a partial refund's `operations[].id`. Prefers
     * {@see INFO_CAPTURE_OPERATION_ID} (the freshest known operation id, persisted whenever a
     * `paid`/`hold_confirmed` event carries `operations[0].transaction_id` — see
     * {@see \NovaPay\Payment\Model\OrderStatusApplier::persistCaptureOperationId()}), falling back to
     * the original `/v1/add-payment` response id ({@see INFO_PAYMENT_ID}, set once at checkout) when
     * no capture-time id was ever recorded — the normal case for an order that was never partially
     * completed.
     *
     * @param Payment $payment
     * @return string
     * @throws LocalizedException
     */
    private function requireOperationId(Payment $payment): string
    {
        $captureOperationId = trim((string)$payment->getAdditionalInformation(self::INFO_CAPTURE_OPERATION_ID));
        if ($captureOperationId !== '') {
            return $captureOperationId;
        }

        $operationId = trim((string)$payment->getAdditionalInformation(self::INFO_PAYMENT_ID));
        if ($operationId === '') {
            throw new LocalizedException(
                __('This order has no NovaPay payment id on record; a partial refund cannot be built.')
            );
        }

        return $operationId;
    }
}
