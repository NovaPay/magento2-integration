<?php
/**
 * Copyright © 2026 NovaPay. All rights reserved.
 * Licensed under the MIT License. See LICENSE for details.
 */
declare(strict_types=1);

namespace NovaPay\Payment\Plugin\Sales;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Sales\Api\CreditmemoManagementInterface;
use Magento\Sales\Api\Data\CreditmemoCommentCreationInterface;
use Magento\Sales\Api\Data\CreditmemoCreationArgumentsInterface;
use Magento\Sales\Api\Data\CreditmemoInterface;
use Magento\Sales\Api\InvoiceRepositoryInterface;
use Magento\Sales\Api\RefundInvoiceInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Creditmemo;
use Magento\Sales\Model\OrderFactory;
use Magento\Sales\Model\ResourceModel\Order as OrderResource;
use NovaPay\Payment\Gateway\Config;
use NovaPay\Payment\Model\PostbackProcessor;
use NovaPay\Payment\Model\Refund\RefundLedger;

/**
 * Holds the NovaPay per-session apply lock ({@see PostbackProcessor::lockNameForSession()}) while an
 * ONLINE refund of a NovaPay order runs, released only after the core refund (and its database
 * transaction) has finished.
 *
 * The refund gateway command calls `POST /v1/void` inside that transaction, and NovaPay may send the
 * `voided` postback before it is committed. Without the lock the postback could read the order
 * without the new credit memo, take the refund for one made in the NovaPay cabinet and create a
 * second, automatic credit memo. With it, the postback waits and then sees Magento's ledger equal to
 * NovaPay's refunded amount. Offline credit memos (including the automatic ones created inside the
 * postback pipeline, which already holds the lock) are not affected.
 *
 * Both online refund entry points are covered:
 * - {@see CreditmemoManagementInterface::refund()}: the admin credit memo form, the NovaPay refund
 *   form. The order was loaded before the lock, so once the lock is held it is compared with the
 *   database ({@see assertOrderUnchanged()}): when a postback changed it meanwhile, the refund is
 *   refused instead of running on (and later saving) stale data.
 * - {@see RefundInvoiceInterface::execute()}: REST `POST /V1/invoice/:id/refund`. The session id is
 *   read from a separately loaded order; core loads its own order only inside the call, i.e. after
 *   the lock is held, so it sees the current data.
 */
class OnlineRefundSessionLockPlugin
{
    /**
     * Longest wait for a postback of the same session to finish.
     */
    private const LOCK_TIMEOUT_SECONDS = 15;

    private const AMOUNT_TOLERANCE = 0.001;

    /**
     * Payment values the postback pipeline writes; any difference means the order changed.
     */
    private const WATCHED_PAYMENT_INFO = [
        'novapay_status',
        'novapay_refunded_operations',
        'novapay_partial_refund_confirmed_amount',
    ];

    /**
     * @param LockManagerInterface $lockManager
     * @param OrderFactory $orderFactory
     * @param OrderResource $orderResource
     * @param InvoiceRepositoryInterface $invoiceRepository
     * @param RefundLedger $refundLedger
     */
    public function __construct(
        private readonly LockManagerInterface $lockManager,
        private readonly OrderFactory $orderFactory,
        private readonly OrderResource $orderResource,
        private readonly InvoiceRepositoryInterface $invoiceRepository,
        private readonly RefundLedger $refundLedger
    ) {
    }

    /**
     * Refund a credit memo under the session lock.
     *
     * @param CreditmemoManagementInterface $subject
     * @param callable $proceed
     * @param CreditmemoInterface $creditmemo
     * @param bool $offlineRequested
     * @return CreditmemoInterface
     * @throws LocalizedException when the lock cannot be acquired in time or the order changed
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function aroundRefund(
        CreditmemoManagementInterface $subject,
        callable $proceed,
        CreditmemoInterface $creditmemo,
        $offlineRequested = false
    ) {
        $order = !$offlineRequested && $creditmemo instanceof Creditmemo ? $creditmemo->getOrder() : null;
        $sessionId = $order instanceof Order ? $this->getSessionId($order) : '';
        if ($sessionId === '') {
            return $proceed($creditmemo, $offlineRequested);
        }

        $lockName = $this->lock($sessionId);
        try {
            $this->assertOrderUnchanged($order);

            return $proceed($creditmemo, $offlineRequested);
        } finally {
            $this->lockManager->unlock($lockName);
        }
    }

    /**
     * Refund an invoice (REST) under the session lock.
     *
     * @param RefundInvoiceInterface $subject
     * @param callable $proceed
     * @param int $invoiceId
     * @param array $items
     * @param bool $isOnline
     * @param bool $notify
     * @param bool $appendComment
     * @param CreditmemoCommentCreationInterface|null $comment
     * @param CreditmemoCreationArgumentsInterface|null $arguments
     * @return int
     * @throws LocalizedException when the lock cannot be acquired in time
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     * @SuppressWarnings(PHPMD.ExcessiveParameterList)
     */
    public function aroundExecute(
        RefundInvoiceInterface $subject,
        callable $proceed,
        $invoiceId,
        array $items = [],
        $isOnline = false,
        $notify = false,
        $appendComment = false,
        ?CreditmemoCommentCreationInterface $comment = null,
        ?CreditmemoCreationArgumentsInterface $arguments = null
    ) {
        $sessionId = $isOnline ? $this->getInvoiceSessionId((int)$invoiceId) : '';
        if ($sessionId === '') {
            return $proceed($invoiceId, $items, $isOnline, $notify, $appendComment, $comment, $arguments);
        }

        $lockName = $this->lock($sessionId);
        try {
            return $proceed($invoiceId, $items, $isOnline, $notify, $appendComment, $comment, $arguments);
        } finally {
            $this->lockManager->unlock($lockName);
        }
    }

    /**
     * Acquire the session lock.
     *
     * @param string $sessionId
     * @return string the lock name
     * @throws LocalizedException
     */
    private function lock(string $sessionId): string
    {
        $lockName = PostbackProcessor::lockNameForSession($sessionId);
        if (!$this->lockManager->lock($lockName, self::LOCK_TIMEOUT_SECONDS)) {
            throw new LocalizedException(__(
                'NovaPay is updating this order right now. Wait a moment and try the refund again.'
            ));
        }

        return $lockName;
    }

    /**
     * Refuse the refund when a postback changed the order after it was loaded.
     *
     * Compared with the database: `base_total_refunded`, the status, the NovaPay status, the
     * NovaPay-confirmed refunds and the automatic credit memos of the refund ledger.
     *
     * @param Order $order the order the refund is about to run on
     * @return void
     * @throws LocalizedException
     */
    private function assertOrderUnchanged(Order $order): void
    {
        $stored = $this->orderFactory->create();
        $this->orderResource->load($stored, (int)$order->getEntityId());
        if (!$stored->getEntityId()) {
            return;
        }

        $changed = abs((float)$stored->getBaseTotalRefunded() - (float)$order->getBaseTotalRefunded())
                > self::AMOUNT_TOLERANCE
            || (string)$stored->getStatus() !== (string)$order->getStatus();

        $payment = $order->getPayment();
        $storedPayment = $stored->getPayment();
        if (!$changed && $payment !== null && $storedPayment !== null) {
            foreach (self::WATCHED_PAYMENT_INFO as $key) {
                if ($this->infoValue($storedPayment->getAdditionalInformation($key))
                    !== $this->infoValue($payment->getAdditionalInformation($key))
                ) {
                    $changed = true;
                    break;
                }
            }
            $ledger = $this->refundLedger->read($payment);
            $storedLedger = $this->refundLedger->read($storedPayment);
            $changed = $changed
                || count($ledger['auto_creditmemos']) !== count($storedLedger['auto_creditmemos'])
                || abs($ledger['unattributed'] - $storedLedger['unattributed']) > self::AMOUNT_TOLERANCE;
        }

        if ($changed) {
            throw new LocalizedException(__('The order was updated by NovaPay, reload the page and try again.'));
        }
    }

    /**
     * A payment info value as a comparable string.
     *
     * @param mixed $value
     * @return string
     */
    private function infoValue(mixed $value): string
    {
        return is_scalar($value) ? trim((string)$value) : '';
    }

    /**
     * The NovaPay session id of the invoice's order, read from the database; '' when not NovaPay.
     *
     * @param int $invoiceId
     * @return string
     */
    private function getInvoiceSessionId(int $invoiceId): string
    {
        $orderId = (int)$this->invoiceRepository->get($invoiceId)->getOrderId();
        $order = $this->orderFactory->create();
        $this->orderResource->load($order, $orderId);

        return $order->getEntityId() ? $this->getSessionId($order) : '';
    }

    /**
     * The NovaPay session id of an order, '' for other payment methods.
     *
     * @param Order $order
     * @return string
     */
    private function getSessionId(Order $order): string
    {
        $payment = $order->getPayment();
        if ($payment === null || $payment->getMethod() !== Config::CODE) {
            return '';
        }

        $sessionId = $payment->getAdditionalInformation('novapay_session_id');

        return is_scalar($sessionId) ? trim((string)$sessionId) : '';
    }
}
