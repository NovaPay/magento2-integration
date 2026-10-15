<?php
/**
 * Copyright © 2026 NovaPay. All rights reserved.
 * Licensed under the MIT License. See LICENSE for details.
 */
declare(strict_types=1);

namespace NovaPay\Payment\Cron;

use Magento\Framework\Data\Collection as DataCollection;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Sales\Api\Data\OrderPaymentInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use NovaPay\Payment\Gateway\Config;
use NovaPay\Payment\Gateway\Exception\InvalidKeyException;
use NovaPay\Payment\Gateway\Http\Client;
use NovaPay\Payment\Gateway\Http\Exception\ApiException;
use NovaPay\Payment\Gateway\Request\GetStatusBuilder;
use NovaPay\Payment\Model\Order\StatusCode;
use NovaPay\Payment\Model\Postback\V3Parser;
use NovaPay\Payment\Model\PostbackProcessor;
use NovaPay\Payment\Model\StatusMapper;
use Psr\Log\LoggerInterface;

/**
 * `POST /v1/get-status` fallback for missed postbacks, run every 15 minutes via etc/crontab.xml.
 * Polls orders whose NovaPay session has sat in an awaiting-resolution status past
 * {@see STALE_AFTER_SECONDS}, and feeds the response through the same pipeline a real postback
 * uses ({@see PostbackProcessor::applyParsed()}), including the session-match guard.
 *
 * No signature verification here — the response arrives over the HTTPS request this module made.
 * Reuses {@see V3Parser} regardless of the configured *postback* version (that setting only governs
 * the inbound webhook schema, not this synchronous response). The `/v1/get-status` response schema
 * is not fully documented; this class assumes it mirrors the v3 postback payload's field names
 * where present.
 *
 * Everything scope-dependent here is read at the *order's* own store — API credentials for the
 * outbound call ({@see Client::request()}, {@see GetStatusBuilder}) and, for symmetry with the
 * postback path, the store id handed to {@see PostbackProcessor::applyParsed()}. Unlike an inbound
 * postback there is nothing untrusted about that store id: this cron picked the order first and
 * derived the store from it, rather than the other way round.
 *
 * Each order's sync is isolated so one broken order never aborts the batch. The query is ordered
 * oldest-`updated_at`-first, and {@see markSynced()} always runs in a `finally` block so a failing
 * API call still advances the per-order cooldown ({@see SYNC_COOLDOWN_SECONDS}) instead of being
 * retried on every run. All time comparisons use the injected {@see DateTime} (UTC), never
 * `time()`/`date()`.
 *
 * One save chain per order, never a stale object: {@see PostbackProcessor::applyParsed()} loads the
 * order itself (through its own matcher, a different object from this cron's collection item) and
 * saves its changes (state/status, invoice, transactions, payment additional_information). The
 * `novapay_last_sync_at` stamp is therefore written on a FRESH order re-read through
 * {@see OrderRepositoryInterface::get()} after the pipeline has finished, never on the collection
 * item, which would still hold the pre-apply data and overwrite everything the pipeline just saved.
 *
 * Concurrency: the whole run is wrapped in a non-blocking {@see LockManagerInterface} lock
 * (`novapay_sync_status`, timeout 0). A second `cron:run` process starting while a run is still in
 * progress logs `cron.sync_skipped_locked` (INFO, gated on {@see Config::isLoggingEnabled()}) and
 * returns without querying or calling the API. The lock also covers the stale-order query, so a run
 * that starts right after another one finished sees the previous run's `novapay_last_sync_at` stamps
 * and the per-order cooldown applies. The lock is released in a `finally` block.
 *
 * @api
 */
class SyncStatus
{
    /**
     * Only orders left waiting for NovaPay longer than this are polled — a fresh order is normally
     * resolved by a real postback within seconds; this cron exists purely to recover from a missed
     * delivery, not to race one.
     */
    private const STALE_AFTER_SECONDS = 3600;

    /**
     * Upper bound per run so a large backlog cannot make a single cron execution run indefinitely;
     * remaining orders are picked up on the next 15-minute run.
     */
    private const BATCH_LIMIT = 50;

    /**
     * Minimum gap between two `/v1/get-status` calls for the *same* order — independent of
     * {@see BATCH_LIMIT}/ordering, this is the hard per-order rate limit.
     */
    private const SYNC_COOLDOWN_SECONDS = 3600;

    /**
     * Statuses that mean "still waiting on NovaPay to resolve this session". Mirrors
     * etc/config.xml's `<status_*>` defaults, same pattern as
     * {@see \NovaPay\Payment\Model\StatusMapper::DEFAULT_LABEL}.
     *
     * `novapay_failed` is included because a failed payment can still be retried in the same
     * NovaPay session: a failed-then-paid order whose `holded`/`paid` postback was lost must still
     * be synced. It is polled only in state `pending_payment` (see {@see collectStaleOrders()}):
     * legacy orders that the earlier behavior left canceled with novapay_failed are not polled. A
     * failed order past the pending payment lifetime is canceled ({@see cancelIfFailedTooLong()}).
     *
     * {@see markSynced()} does not bump `sales_order.updated_at` (only the payment row changes, so
     * snapshot versioning skips the order UPDATE): cooled-down orders still occupy batch slots and
     * are skipped after the query. Failed orders are bounded by {@see cancelIfFailedTooLong()}.
     */
    private const STALE_STATUSES = [
        StatusCode::PENDING,
        Order::STATE_PENDING_PAYMENT,
        StatusCode::HOLDED,
        StatusCode::FAILED,
    ];

    private const PAYMENT_TABLE = 'sales_order_payment';
    private const INFO_SESSION_ID = 'novapay_session_id';
    private const INFO_LAST_SYNC_AT = 'novapay_last_sync_at';

    /**
     * Process-wide lock name serializing runs of this job (see class docblock).
     */
    private const LOCK_NAME = 'novapay_sync_status';

    /**
     * @param OrderCollectionFactory $orderCollectionFactory
     * @param GetStatusBuilder $getStatusBuilder
     * @param Client $client
     * @param V3Parser $parser
     * @param PostbackProcessor $postbackProcessor
     * @param OrderRepositoryInterface $orderRepository
     * @param DateTime $dateTime
     * @param LoggerInterface $logger
     * @param Config $config
     * @param LockManagerInterface $lockManager
     * @param StatusMapper $statusMapper
     */
    public function __construct(
        private readonly OrderCollectionFactory $orderCollectionFactory,
        private readonly GetStatusBuilder $getStatusBuilder,
        private readonly Client $client,
        private readonly V3Parser $parser,
        private readonly PostbackProcessor $postbackProcessor,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly DateTime $dateTime,
        private readonly LoggerInterface $logger,
        private readonly Config $config,
        private readonly LockManagerInterface $lockManager,
        private readonly StatusMapper $statusMapper
    ) {
    }

    /**
     * Poll `/v1/get-status` for every stale, still-awaiting-resolution order and apply the result.
     * Each order is isolated — an unexpected exception from one order's sync is logged and the
     * batch continues. Logs one INFO-level summary per run (orders considered vs. unexpected
     * failures), gated on {@see Config::isLoggingEnabled()}; per-order failures are always logged
     * regardless. Skips the whole run when another process holds the `novapay_sync_status` lock.
     *
     * @return void
     */
    public function execute(): void
    {
        if (!$this->lockManager->lock(self::LOCK_NAME, 0)) {
            if ($this->config->isLoggingEnabled()) {
                $this->logger->info('NovaPay get-status cron run skipped: another run is still in progress.', [
                    'event' => 'cron.sync_skipped_locked',
                ]);
            }

            return;
        }

        try {
            $this->runLocked();
        } finally {
            $this->lockManager->unlock(self::LOCK_NAME);
        }
    }

    /**
     * The body of {@see execute()}, run only while the `novapay_sync_status` lock is held.
     *
     * @return void
     */
    private function runLocked(): void
    {
        $orders = $this->collectStaleOrders();
        $failed = 0;

        foreach ($orders as $order) {
            try {
                $this->syncOne($order);
            } catch (\Throwable $e) {
                $failed++;
                $this->logger->critical(
                    'NovaPay get-status sync failed unexpectedly for an order; continuing with the '
                    . 'rest of the batch.',
                    [
                        'event' => 'cron.sync_error',
                        'order_increment_id' => $order->getIncrementId(),
                        'exception' => $e->getMessage(),
                    ]
                );
            }
        }

        if ($this->config->isLoggingEnabled()) {
            $this->logger->info('NovaPay get-status cron run finished.', [
                'event' => 'cron.sync_summary',
                'orders_considered' => count($orders),
                'orders_failed_unexpectedly' => $failed,
            ]);
        }
    }

    /**
     * Orders with a NovaPay session that have sat in a stale status past the threshold, oldest
     * `updated_at` first. A novapay_failed order counts only in state `pending_payment`. Restricted
     * to `novapay` payment-method orders — the joined `additional_information` LIKE match alone is
     * not method-specific.
     *
     * @return Order[]
     */
    private function collectStaleOrders(): array
    {
        $collection = $this->orderCollectionFactory->create();
        $paymentTable = $collection->getResource()->getTable(self::PAYMENT_TABLE);

        $staleBefore = $this->dateTime->gmtDate(
            'Y-m-d H:i:s',
            $this->dateTime->gmtTimestamp() - self::STALE_AFTER_SECONDS
        );

        $collection->addFieldToFilter('status', ['in' => self::STALE_STATUSES]);
        // novapay_failed only while still awaiting payment: (status != failed OR state = pending_payment).
        $collection->addFieldToFilter(
            ['status', 'state'],
            [['neq' => StatusCode::FAILED], ['eq' => Order::STATE_PENDING_PAYMENT]]
        );
        $collection->addFieldToFilter('updated_at', ['lt' => $staleBefore]);
        $collection->getSelect()->join(
            ['novapay_payment' => $paymentTable],
            'novapay_payment.parent_id = main_table.entity_id',
            []
        )->where(
            'novapay_payment.method = ?',
            Config::CODE
        )->where(
            'novapay_payment.additional_information LIKE ?',
            '%"' . self::INFO_SESSION_ID . '":"%'
        );
        $collection->addOrder('updated_at', DataCollection::SORT_ORDER_ASC);
        $collection->setPageSize(self::BATCH_LIMIT);

        /** @var Order[] $orders this is a sales order collection; every item is an Order */
        $orders = array_values($collection->getItems());

        return $orders;
    }

    /**
     * Poll `/v1/get-status` for a single order's session and feed the result into the shared
     * pipeline. Skips (without an API call) an order synced within the last
     * {@see SYNC_COOLDOWN_SECONDS}. {@see markSynced()} runs in a `finally` block around the whole
     * request/parse/apply sequence, so the cooldown applies whether the API call throws, the
     * response is unparsable, or it succeeds.
     *
     * @param Order $order
     * @return void
     */
    private function syncOne(Order $order): void
    {
        $payment = $order->getPayment();
        $sessionId = trim((string)($payment?->getAdditionalInformation(self::INFO_SESSION_ID) ?? ''));
        if ($sessionId === '' || $payment === null) {
            return;
        }

        if ($this->wasSyncedRecently($payment)) {
            return;
        }

        try {
            $this->syncOneOrThrow($order, $sessionId);
        } finally {
            $this->markSynced((int)$order->getId());
        }
    }

    /**
     * The actual request/parse/apply sequence, factored out of {@see syncOne()} so
     * {@see markSynced()} can wrap it in a `finally` block regardless of how it exits.
     *
     * @param Order $order
     * @param string $sessionId
     * @return void
     */
    private function syncOneOrThrow(Order $order, string $sessionId): void
    {
        $storeId = (int)$order->getStoreId();

        try {
            $response = $this->client->request(
                '/v1/get-status',
                $this->getStatusBuilder->build(['session_id' => $sessionId, 'store_id' => $storeId]),
                $storeId
            );
        } catch (ApiException|InvalidKeyException $e) {
            $this->logger->error('NovaPay get-status sync failed for an order; will retry on the next run.', [
                'event' => 'cron.sync_api_error',
                'order_increment_id' => $order->getIncrementId(),
                'exception' => $e->getMessage(),
            ]);

            return;
        }

        // The response may not echo the session id back; the parser requires "id" to be present.
        $response['id'] = $response['id'] ?? $sessionId;

        $parsed = $this->parser->parse($response);
        if ($parsed === null) {
            return;
        }

        $this->postbackProcessor->applyParsed(
            $parsed,
            hash('sha256', (string)json_encode($response)),
            $storeId
        );

        if ($parsed->getStatus() === 'failed') {
            $this->cancelIfFailedTooLong((int)$order->getId(), $storeId);
        }
    }

    /**
     * NovaPay still reports `failed` for this order's session (just confirmed by get-status): once
     * the order is older than core's pending payment lifetime (`sales/orders/delete_pending_after`,
     * minutes, store scope, measured from `created_at` like a checkout that never got paid), cancel
     * it through {@see StatusMapper::cancelStaleFailedOrder()} so the stock reservation is released.
     * Core's own cleanup cron does not do this: it selects by status `pending_payment`, and a failed
     * order has status novapay_failed. Works on a fresh order (see {@see markSynced()}).
     *
     * @param int $orderId
     * @param int $storeId
     * @return void
     */
    private function cancelIfFailedTooLong(int $orderId, int $storeId): void
    {
        $order = $this->orderRepository->get($orderId);
        $createdAt = $this->parseUtcTimestamp((string)$order->getCreatedAt());
        $lifetimeSeconds = $this->config->getPendingPaymentLifetimeMinutes($storeId) * 60;

        if ($createdAt === false || $this->dateTime->gmtTimestamp() - $createdAt < $lifetimeSeconds) {
            return;
        }

        if ($this->statusMapper->cancelStaleFailedOrder($order)) {
            $this->logger->notice('NovaPay failed order canceled after the pending payment order lifetime.', [
                'event' => 'cron.failed_order_expired',
                'order_increment_id' => $order->getIncrementId(),
                'lifetime_minutes' => (int)($lifetimeSeconds / 60),
            ]);
        }
    }

    /**
     * Whether `$payment`'s `novapay_last_sync_at` is within {@see SYNC_COOLDOWN_SECONDS} of now
     * (per the injected {@see DateTime}'s UTC clock).
     *
     * @param OrderPaymentInterface|null $payment
     * @return bool
     */
    private function wasSyncedRecently(?OrderPaymentInterface $payment): bool
    {
        if ($payment === null) {
            return false;
        }

        $lastSyncAt = trim((string)($payment->getAdditionalInformation(self::INFO_LAST_SYNC_AT) ?? ''));
        if ($lastSyncAt === '') {
            return false;
        }

        $lastSyncTimestamp = $this->parseUtcTimestamp($lastSyncAt);

        return $lastSyncTimestamp !== false
            && ($this->dateTime->gmtTimestamp() - $lastSyncTimestamp) < self::SYNC_COOLDOWN_SECONDS;
    }

    /**
     * Parses a `Y-m-d H:i:s` UTC-stored timestamp (`novapay_last_sync_at`) as UTC, regardless of
     * the running process's default timezone — a bare `strtotime()` would use
     * `date_default_timezone_get()`, which is only forced to UTC inside a real Magento
     * request/cron (`app/bootstrap.php`), not in a bare PHPUnit process.
     *
     * @param string $value
     * @return int|false
     */
    private function parseUtcTimestamp(string $value): int|false
    {
        $date = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value, new \DateTimeZone('UTC'));

        return $date !== false ? $date->getTimestamp() : false;
    }

    /**
     * Persist the `novapay_last_sync_at` bookkeeping marker and save the order (bumping
     * `updated_at`). Called from a `finally` block in {@see syncOne()} so every *attempt* - not
     * just a completed round trip - advances the cooldown. Deliberately does not go through
     * {@see \NovaPay\Payment\Model\StatusMapper} - this is cron-internal bookkeeping, not a
     * NovaPay status/state change.
     *
     * Works on a FRESH order from {@see OrderRepositoryInterface::get()}, never on the collection
     * item {@see syncOne()} received: the pipeline has loaded and saved its own copy of the order in
     * between, so saving the collection item would revert state/status, totals and the payment's
     * additional_information to their pre-apply values (see class docblock). The repository returns
     * either the pipeline's own saved instance (it saves through the same repository) or a new load
     * from the database; both carry the applied changes.
     *
     * @param int $orderId
     * @return void
     */
    private function markSynced(int $orderId): void
    {
        $order = $this->orderRepository->get($orderId);
        $payment = $order->getPayment();
        if ($payment === null) {
            return;
        }

        $payment->setAdditionalInformation(self::INFO_LAST_SYNC_AT, $this->dateTime->gmtDate());
        $this->orderRepository->save($order);
    }
}
