<?php
/**
 * Copyright © 2026 NovaPay. All rights reserved.
 * Licensed under the MIT License. See LICENSE for details.
 */
declare(strict_types=1);

namespace NovaPay\Payment\Gateway\Command;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Payment\Gateway\CommandInterface;
use Magento\Payment\Gateway\Helper\SubjectReader;
use Magento\Sales\Model\Order\Payment;
use NovaPay\Payment\Gateway\Config;
use NovaPay\Payment\Gateway\ErrorMapper;
use NovaPay\Payment\Gateway\Http\Client;
use NovaPay\Payment\Gateway\Http\Exception\ApiException;
use NovaPay\Payment\Gateway\Request\CompleteHoldBuilder;
use NovaPay\Payment\Model\Order\GatewayFailureNote;
use NovaPay\Payment\Model\Order\GatewayFailureNoteRegistry;
use Psr\Log\LoggerInterface;

/**
 * "capture" gateway command — `POST /v1/complete-hold`. Invoked by Magento's native online invoice
 * capture flow ("Capture Online" on a `holded` order) — never by a custom button.
 *
 * Only one complete-hold call is ever valid per hold (NovaPay auto-refunds the uncaptured
 * difference on any call, full or partial), the hold must be less than 29 days old (measured from
 * the authorization transaction's `created_at`), and a partial capture requires a configured
 * {@see Config::getRecipientIdentifier()}. On success, sets
 * `$payment->setIsTransactionPending(true)` (native Magento hook read by
 * {@see \Magento\Sales\Model\Order\Payment\Operations\ProcessInvoiceOperation::execute()}) so the
 * invoice stays pending until the `hold_confirmed` postback marks it paid.
 *
 * @api
 */
class CaptureCommand implements CommandInterface
{
    private const INFO_STATUS = 'novapay_status';
    private const INFO_PAYMENT_ID = 'novapay_payment_id';
    private const INFO_SESSION_ID = 'novapay_session_id';

    /**
     * NovaPay only allows a complete-hold call within 29 days of the hold.
     */
    private const MAX_HOLD_AGE_DAYS = 29;

    /**
     * Float-rounding tolerance when deciding whether the requested capture amount equals the full
     * held amount (order base grand total) — anything closer than this is a full, not partial,
     * capture.
     */
    private const AMOUNT_TOLERANCE = 0.01;

    /**
     * @param CompleteHoldBuilder $requestBuilder
     * @param Client $client
     * @param Config $config
     * @param ErrorMapper $errorMapper
     * @param DateTime $dateTime
     * @param LoggerInterface $logger
     * @param GatewayFailureNoteRegistry $failureNotes
     */
    public function __construct(
        private readonly CompleteHoldBuilder $requestBuilder,
        private readonly Client $client,
        private readonly Config $config,
        private readonly ErrorMapper $errorMapper,
        private readonly DateTime $dateTime,
        private readonly LoggerInterface $logger,
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

        $this->assertHoldIsActive($payment);
        $this->assertNotAlreadyCaptured($order);
        $this->assertHoldNotExpired($payment);

        $heldAmount = (float)$order->getBaseGrandTotal();
        $this->assertAmountValid($amount, $heldAmount);

        $sessionId = trim((string)$payment->getAdditionalInformation(self::INFO_SESSION_ID));
        if ($sessionId === '') {
            throw new LocalizedException(__('This order has no NovaPay session to confirm the hold on.'));
        }

        $buildSubject = ['session_id' => $sessionId, 'store_id' => $storeId];

        $isFullCapture = abs($heldAmount - $amount) <= self::AMOUNT_TOLERANCE;
        if (!$isFullCapture) {
            $buildSubject['amount'] = round($amount, 2);
            $buildSubject['operation_id'] = $this->requireOperationId($payment);
            $buildSubject['recipient_identifier'] = $this->requireRecipientIdentifier($storeId);
        }

        $payload = $this->requestBuilder->build($buildSubject);

        try {
            $this->client->request('/v1/complete-hold', $payload, $storeId);
        } catch (ApiException $e) {
            $this->logger->error('NovaPay complete-hold (capture) call failed.', [
                'event' => 'command.capture_error',
                'order_increment_id' => $order->getIncrementId(),
                'endpoint' => $e->getEndpoint(),
                'http_status' => $e->getHttpStatusCode(),
            ]);

            $error = $this->errorMapper->map($e);
            // Written to the order history by the admin controller after the rolled-back transaction.
            $this->failureNotes->add(new GatewayFailureNote(
                (int)$order->getEntityId(),
                GatewayFailureNote::OPERATION_CAPTURE,
                $amount,
                (string)$order->getBaseCurrencyCode(),
                $error
            ));

            throw $error;
        }

        // Keeps the invoice pending until the hold_confirmed postback (see class docblock).
        $payment->setIsTransactionPending(true);

        return null;
    }

    /**
     * The order's stored `novapay_status` must currently be `holded`.
     *
     * @param Payment $payment
     * @return void
     * @throws LocalizedException
     */
    private function assertHoldIsActive(Payment $payment): void
    {
        $status = trim((string)$payment->getAdditionalInformation(self::INFO_STATUS));
        if ($status !== 'holded') {
            throw new LocalizedException(
                __('This order\'s NovaPay hold is not currently active and cannot be confirmed.')
            );
        }
    }

    /**
     * Exactly one `/v1/complete-hold` call is allowed per hold.
     *
     * @param \Magento\Sales\Model\Order $order
     * @return void
     * @throws LocalizedException
     */
    private function assertNotAlreadyCaptured(\Magento\Sales\Model\Order $order): void
    {
        if ($order->getInvoiceCollection()->getSize() > 0) {
            throw new LocalizedException(
                __('This NovaPay hold has already been confirmed; only one complete-hold call is allowed per hold.')
            );
        }
    }

    /**
     * The hold's age, measured from the authorization transaction's `created_at`, must not exceed
     * {@see MAX_HOLD_AGE_DAYS}.
     *
     * @param Payment $payment
     * @return void
     * @throws LocalizedException
     */
    private function assertHoldNotExpired(Payment $payment): void
    {
        $authTransaction = $payment->getAuthorizationTransaction();
        if ($authTransaction === null || $authTransaction === false) {
            // No authorization transaction to measure age from; assertHoldIsActive() already
            // guarantees novapay_status === 'holded', so this should not normally happen, but fail
            // safe rather than let an un-dated hold bypass the 29-day rule silently.
            throw new LocalizedException(
                __('This order has no NovaPay authorization on record; the hold cannot be confirmed.')
            );
        }

        $heldAt = $this->dateTime->gmtTimestamp((string)$authTransaction->getCreatedAt());
        if ($heldAt === false) {
            // Unparsable created_at should never happen for a native Magento transaction row; fail
            // safe rather than silently treat an un-dated hold as never expiring.
            throw new LocalizedException(
                __('This order\'s NovaPay authorization has no readable date; the hold cannot be confirmed.')
            );
        }

        $ageDays = ($this->dateTime->gmtTimestamp() - $heldAt) / 86400;

        if ($ageDays > self::MAX_HOLD_AGE_DAYS) {
            throw new LocalizedException(
                __(
                    'This NovaPay hold is more than %1 days old and can no longer be confirmed.',
                    self::MAX_HOLD_AGE_DAYS
                )
            );
        }
    }

    /**
     * The capture amount must be greater than zero and not exceed the held amount.
     *
     * @param float $amount
     * @param float $heldAmount
     * @return void
     * @throws LocalizedException
     */
    private function assertAmountValid(float $amount, float $heldAmount): void
    {
        if ($amount <= 0) {
            throw new LocalizedException(__('The capture amount must be greater than zero.'));
        }

        if ($amount > $heldAmount + self::AMOUNT_TOLERANCE) {
            throw new LocalizedException(__('The capture amount cannot exceed the held amount.'));
        }
    }

    /**
     * A partial capture's `operations[0].id`: the numeric NovaPay operation id from the `/v1/payment`
     * response. NovaPay confirmed (2026-10-12) that the spec's `payments[].external_id` is wrong here:
     * with it a partial hold confirmation is impossible.
     *
     * @param Payment $payment
     * @return string
     * @throws LocalizedException
     */
    private function requireOperationId(Payment $payment): string
    {
        $paymentId = trim((string)$payment->getAdditionalInformation(self::INFO_PAYMENT_ID));
        if ($paymentId === '') {
            throw new LocalizedException(
                __('This order has no NovaPay payment id on record; a partial capture cannot be built.')
            );
        }

        return $paymentId;
    }

    /**
     * A partial capture requires a configured recipient identifier (ЄДРПОУ/ІПН).
     *
     * @param int $storeId
     * @return string
     * @throws LocalizedException
     */
    private function requireRecipientIdentifier(int $storeId): string
    {
        $recipientIdentifier = $this->config->getRecipientIdentifier($storeId);
        if ($recipientIdentifier === '') {
            throw new LocalizedException(
                __(
                    'Configure a Recipient identifier (ЄДРПОУ/ІПН) in the NovaPay payment settings '
                    . 'before performing a partial capture.'
                )
            );
        }

        return $recipientIdentifier;
    }
}
