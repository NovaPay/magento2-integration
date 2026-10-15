<?php
/**
 * Copyright © 2026 NovaPay. All rights reserved.
 * Licensed under the MIT License. See LICENSE for details.
 */
declare(strict_types=1);

namespace NovaPay\Payment\Model;

use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Config as OrderConfig;
use NovaPay\Payment\Gateway\Config;
use NovaPay\Payment\Model\Order\StatusCode;
use NovaPay\Payment\Model\Postback\ParsedPostback;
use NovaPay\Payment\Model\Refund\NovaPayRefundState;
use NovaPay\Payment\Model\Refund\RefundSnapshot;
use Psr\Log\LoggerInterface;

/**
 * Owns the hardcoded NovaPay-status → Magento order **state** map. This map is protocol-defined,
 * not admin-configurable: only the human-facing status *label* for each row is a module setting
 * ({@see Config::getMappedOrderStatus()}), and even that is validated here to actually belong to
 * the mapped state (via {@see OrderConfig::getStateStatuses()}) before use — an admin who repoints
 * a status field at a status belonging to a different state would otherwise silently desynchronize
 * the order's state/status pair. On an invalid/missing configured label, this class falls back to
 * the default and logs a warning.
 *
 * This class, together with its single collaborator {@see OrderStatusApplier} (which performs the
 * actual native Magento operations and persistence), is the *only* place order state/status is
 * ever written, whether reached from a real postback ({@see PostbackProcessor}) or the get-status
 * cron fallback ({@see \NovaPay\Payment\Cron\SyncStatus}). Both call {@see StatusMapper::apply()};
 * there is no other entry point.
 *
 * Before anything is written, a layered status-progression guard decides whether the incoming
 * status may follow the stored one ({@see blockReason()}): a canceled/closed Magento order
 * accepts only {@see ALLOWED_ON_CLOSED_ORDER}, whatever its stored NovaPay status; the terminal
 * matrix {@see ALLOWED_FROM_STATUS} (paid / hold_confirmed / voided / failed / expired accept only
 * the listed follow-ups); a stale `failed` after `holded` ({@see FAILED_BLOCKED_FROM}); and the
 * lifecycle rank {@see LIFECYCLE_RANK} (no move back to an earlier status such as
 * `holded -> processing`). Blocked transitions are logged as `postback.blocked_transition` with a
 * `reason` naming the layer.
 *
 * The one write that is not driven by a NovaPay event is {@see cancelStaleFailedOrder()}, called
 * only by the get-status cron: a failed order still awaiting payment past the store's pending
 * payment lifetime is canceled, the way core cleanup cancels `pending_payment` orders.
 *
 * @api
 */
class StatusMapper
{
    /**
     * NovaPay session status → Magento order state. Deliberately excludes the two transient
     * statuses (`processing_hold_completion`, `processing_void`), which are explicit no-ops, and
     * any status not in NovaPay's documented list, which is logged and ignored.
     */
    private const STATE_MAP = [
        'created' => Order::STATE_NEW,
        'processing' => Order::STATE_PENDING_PAYMENT,
        'holded' => Order::STATE_PROCESSING,
        'hold_confirmed' => Order::STATE_PROCESSING,
        'paid' => Order::STATE_PROCESSING,
        'failed' => Order::STATE_PENDING_PAYMENT,
        'voided' => Order::STATE_CLOSED,
        'expired' => Order::STATE_CANCELED,
    ];

    /**
     * Explicit no-op statuses ("Transient processing_hold_completion, processing_void → no
     * change"). Listed separately from an "unknown status" so the two cases log differently.
     */
    private const TRANSIENT_STATUSES = ['processing_hold_completion', 'processing_void'];

    /**
     * Status-progression guard: a signed postback is authenticated, not automatically *authorized
     * to transition* the order — an out-of-order/replayed `expired`/`failed`/`holded` postback must
     * never downgrade an order that has already reached one of the "terminal-ish" statuses below
     * (live-proven: a `paid` order was driven to `canceled` with the money retained, and a `voided`
     * order was flipped back to `paid`).
     *
     * Keyed by the order's *currently stored* `novapay_status` (persisted by
     * {@see OrderStatusApplier::persistPaymentInfo()} only on an actual session-level status
     * transition, never by a partial-void event); the value is the exhaustive allow-list of
     * incoming statuses that may still be applied from there. This is the first, authoritative layer:
     * for a stored status present as a key here, only the listed incoming statuses are accepted.
     * A stored status NOT present as a key is still subject to the second layer,
     * {@see LIFECYCLE_RANK}, which blocks a move back to an earlier lifecycle status (e.g.
     * `holded -> processing`) while leaving forward progression and `failed`/`expired` open.
     * Once `voided`, only another `voided` (e.g. a second partial-refund event) is ever accepted.
     *
     * `expired` is terminal ("canceled order resurrection" — live-proven: a late/replayed `paid`
     * postback for an order already canceled by an earlier `expired` postback re-invoiced/re-held an
     * order the merchant had already treated as dead): it accepts only a repeat of itself.
     *
     * `failed` is NOT terminal at NovaPay: the customer can retry on the payment frame within the same
     * session (same session id) and pay successfully, so NovaPay follows `failed` with `processing`,
     * `holded`, `paid` / `hold_confirmed`, or the session later expires. A `failed` order therefore
     * stays in `pending_payment` (see {@see STATE_MAP}) and accepts those follow-ups — but only while
     * the Magento order is still open. Once the order is canceled or closed (a legacy order canceled
     * by the pre-2026-10 `failed` behavior, an admin cancel, or the get-status cron's
     * {@see cancelStaleFailedOrder()}), {@see ALLOWED_ON_CLOSED_ORDER} applies first.
     *
     * {@see apply()} additionally logs any blocked transition FROM `failed`/`expired` TO a
     * money-taking status ({@see MONEY_TAKING_STATUSES}) at CRITICAL rather than WARNING — that case
     * means NovaPay reports money taken or held on an order the merchant already canceled, which
     * needs a human to reconcile.
     */
    private const ALLOWED_FROM_STATUS = [
        'paid' => ['paid', 'voided'],
        'hold_confirmed' => ['hold_confirmed', 'voided'],
        'voided' => ['voided'],
        'failed' => ['failed', 'processing', 'holded', 'paid', 'hold_confirmed', 'expired'],
        'expired' => ['expired'],
    ];

    /**
     * Magento order states that are final: canceled (by NovaPay, an admin or cron) or closed.
     */
    private const CLOSED_ORDER_STATES = [Order::STATE_CANCELED, Order::STATE_CLOSED];

    /**
     * The only incoming statuses accepted while the Magento order is canceled or closed
     * ({@see CLOSED_ORDER_STATES}), whatever the stored NovaPay status: `expired` (only relabels the
     * dead order, {@see OrderStatusApplier} does not cancel twice) and `voided` (the confirmation of
     * an admin hold release or a full refund, which arrives after Magento already canceled/closed the
     * order). Both still pass the remaining guard layers. Everything else would reopen a dead order:
     * money-taking statuses are blocked CRITICAL `money_taking_resurrection` (unless the stored status
     * is already {@see SETTLED_STORED_STATUSES}), the rest WARNING `order_closed`.
     */
    private const ALLOWED_ON_CLOSED_ORDER = ['expired', 'voided'];

    /**
     * Stored statuses meaning the money was already taken or returned at NovaPay. A money-taking
     * status blocked on a closed order with one of these stored is a replay of the settled payment,
     * not money newly taken on a dead order, so it stays WARNING (as before the closed-order layer).
     */
    private const SETTLED_STORED_STATUSES = ['paid', 'hold_confirmed', 'voided'];

    /**
     * Second layer of the status-progression guard: the position of each status in the normal
     * NovaPay session lifecycle. When BOTH the stored and the incoming status have a rank and the
     * incoming rank is lower, the transition is a downgrade and is blocked (logged with reason
     * `rank_downgrade`). This closes the hole below the terminal statuses in
     * {@see ALLOWED_FROM_STATUS}: an out-of-order `processing`/`created` arriving after `holded`
     * (a different body, so the payload-hash idempotency does not catch it) would otherwise drop the
     * order back to `pending_payment`/`new` and overwrite `novapay_status`, after which
     * {@see \NovaPay\Payment\Gateway\Command\CaptureCommand} refuses to confirm the hold.
     *
     * `failed`/`expired` are deliberately NOT ranked: a hold legitimately expires and a payment can
     * fail before the money is held, so `holded -> expired`, `created -> failed` and
     * `processing -> failed` stay allowed. `holded -> failed` is the exception, see
     * {@see FAILED_BLOCKED_FROM}. `paid` and `hold_confirmed` share a rank; the move between them is
     * governed by {@see ALLOWED_FROM_STATUS} alone.
     */
    private const LIFECYCLE_RANK = [
        'created' => 0,
        'processing' => 1,
        'holded' => 2,
        'paid' => 3,
        'hold_confirmed' => 3,
        'voided' => 4,
    ];

    /**
     * Stored statuses after which an incoming `failed` is stale and blocked (WARNING, reason
     * `stale_failed_after_hold`). The customer can retry within the same NovaPay session, so a late
     * `failed` from an earlier attempt can arrive after the retry's `holded`; the money is held, and
     * the order must not drop back to `pending_payment` / novapay_failed. The statuses above
     * `holded` (paid / hold_confirmed / voided) already refuse `failed` via {@see ALLOWED_FROM_STATUS}.
     */
    private const FAILED_BLOCKED_FROM = ['holded'];

    /**
     * Stored statuses whose blocked follow-ups can mean "money moved on an order the merchant already
     * treated as canceled" — used only to decide the log severity of a blocked transition, see
     * {@see MONEY_TAKING_STATUSES} and {@see blockReason()}. From `failed`, a money-taking status is
     * only blocked by the closed-order layer, so that CRITICAL case always concerns a dead order.
     */
    private const CANCELED_STORED_STATUSES = ['failed', 'expired'];

    /**
     * Incoming statuses that mean NovaPay captured (`paid`, `hold_confirmed`) or blocked (`holded`)
     * the customer's funds — a blocked transition INTO one of these FROM
     * {@see CANCELED_STORED_STATUSES} means money moved on an order the merchant already canceled,
     * logged at CRITICAL instead of WARNING. `holded` is included because the customer's money is
     * blocked by it just the same; this also raises a blocked `expired -> holded` from WARNING to
     * CRITICAL (still blocked, only the severity changed).
     */
    private const MONEY_TAKING_STATUSES = ['paid', 'hold_confirmed', 'holded'];

    private const INFO_STATUS = 'novapay_status';

    /**
     * Float-rounding tolerance for the operations[] amount-vs-refunded_amount reconciliation in
     * {@see isPartialVoid()}.
     */
    private const AMOUNT_TOLERANCE = 0.01;

    /**
     * Default status label per NovaPay status, mirrored from etc/config.xml's `<status_*>`
     * defaults — the hardcoded fallback used when the admin-configured label is empty or does not
     * belong to the mapped state.
     *
     * Values are order *status* codes, not states; the `Order::STATE_PENDING_PAYMENT` constant
     * appears here only because for Magento's core built-in `pending_payment` status the status code
     * is identical to its state value, which is why core has no matching `STATUS_*` constant to use
     * instead. `failed`/`voided` default to the module's own `novapay_failed`/`novapay_refunded`
     * (assigned to `pending_payment`/`closed` by the data patches; `novapay_failed` also stays
     * assigned to `canceled` for orders canceled by the pre-2026-10 behavior) rather than core
     * `pending_payment`/`closed`, so NovaPay outcomes stay distinguishable.
     */
    private const DEFAULT_LABEL = [
        'created' => StatusCode::PENDING,
        'processing' => Order::STATE_PENDING_PAYMENT,
        'holded' => StatusCode::HOLDED,
        'hold_confirmed' => StatusCode::PAID,
        'paid' => StatusCode::PAID,
        'failed' => StatusCode::FAILED,
        'voided' => StatusCode::REFUNDED,
        'expired' => StatusCode::EXPIRED,
    ];

    /**
     * @param Config $config
     * @param OrderConfig $orderConfig
     * @param OrderStatusApplier $applier
     * @param LoggerInterface $logger
     * @param NovaPayRefundState $refundState
     */
    public function __construct(
        private readonly Config $config,
        private readonly OrderConfig $orderConfig,
        private readonly OrderStatusApplier $applier,
        private readonly LoggerInterface $logger,
        private readonly NovaPayRefundState $refundState
    ) {
    }

    /**
     * Whether `$payloadHash` was already applied to `$order` (a bounded history, not just the
     * single most recent hash — see {@see OrderStatusApplier::hasProcessedHash()}). Read by
     * {@see \NovaPay\Payment\Model\PostbackProcessor} before {@see apply()} is called, so a
     * replayed-out-of-order postback never reaches a side effect at all.
     *
     * @param Order $order
     * @param string $payloadHash
     * @return bool
     */
    public function hasProcessedHash(Order $order, string $payloadHash): bool
    {
        return $this->applier->hasProcessedHash($order, $payloadHash);
    }

    /**
     * Apply a parsed, already order-matched, already session-verified, already idempotency-checked
     * postback (or get-status response) to `$order`. The only side effect for a transient or unknown
     * status is a log line — no order or payment mutation, no save.
     *
     * Unchanged status: when the incoming session status equals the stored `novapay_status` (and the
     * event is not a partial void, which never changes the session status and must always reach
     * {@see OrderStatusApplier::applyPartialVoid()}), the event is treated as a repeat of what the
     * order already reflects. The applier's apply() is not called, so there is no side effect, no
     * state/status write and no history entry. Only the payload hash is recorded
     * ({@see OrderStatusApplier::recordPayloadHash()}) so a byte-identical repeat is caught earlier as
     * a duplicate, and `postback.unchanged` is logged. Without this, every hourly get-status poll of
     * an order waiting in `holded` re-ran the side effect and added a history comment, because each
     * get-status response has a different body (different hash) and a same-to-same transition is
     * allowed.
     *
     * This never blocks a legitimate re-apply: `novapay_status` is written in memory by
     * {@see OrderStatusApplier::apply()} and persisted only by its single final save, after the side
     * effect. If a `paid`/`holded` application failed half-way (e.g. invoice creation threw), nothing
     * was saved, the stored status still differs from the incoming one, and the next postback or
     * get-status poll applies it normally.
     *
     * `voided` on an invoiced, not fully refunded order is first checked for a partial refund
     * ({@see applyPartialRefundIfConfirmed()}): the order then stays paid and `novapay_status` stays
     * `paid`/`hold_confirmed`, so a later `voided` is re-evaluated here (a byte-identical one is a
     * duplicate) and adds no second comment for the same refunded amount.
     *
     * @param Order $order
     * @param ParsedPostback $parsed
     * @param string $payloadHash sha256 of the raw payload (postback) or normalized response
     *     (cron), stored on success so a byte-identical repeat is detected as a duplicate
     * @param string $source where the event came from, shown in the order history entry:
     *     {@see OrderStatusApplier::SOURCE_POSTBACK} or {@see OrderStatusApplier::SOURCE_GET_STATUS}
     * @return void
     */
    public function apply(
        Order $order,
        ParsedPostback $parsed,
        string $payloadHash,
        string $source = OrderStatusApplier::SOURCE_POSTBACK
    ): void {
        $status = $parsed->getStatus();

        if (in_array($status, self::TRANSIENT_STATUSES, true)) {
            if ($this->config->isLoggingEnabled((int)$order->getStoreId())) {
                $this->logger->info('NovaPay postback status is transient; no order change applied.', [
                    'event' => 'postback.transient',
                    'order_increment_id' => $order->getIncrementId(),
                    'novapay_status' => $status,
                ]);
            }

            return;
        }

        $storedStatus = $this->getStoredStatus($order);
        if ($this->isUnchangedStatus($storedStatus, $parsed)) {
            $this->applier->recordPayloadHash($order, $payloadHash);
            $this->logUnchanged($order, $status, $source);

            return;
        }

        $blockReason = $this->blockReason($order, $storedStatus, $status);
        if ($blockReason !== '') {
            $this->logBlockedTransition($order, $storedStatus, $status, $blockReason);

            return;
        }

        if ($status === 'voided' && $this->applyPartialRefundIfConfirmed($order, $parsed, $payloadHash, $source)) {
            $this->logPositiveVerdict($order, $status, $payloadHash);

            return;
        }

        if ($status === 'voided' && $this->isPartialVoid($parsed)) {
            $this->applier->applyPartialVoid($order, $parsed, $payloadHash, $source);
            $this->logPositiveVerdict($order, $status, $payloadHash);

            return;
        }

        if (!isset(self::STATE_MAP[$status])) {
            $this->logger->warning('NovaPay postback status is not recognized; no order change applied.', [
                'event' => 'postback.unrecognized_status',
                'order_increment_id' => $order->getIncrementId(),
                'novapay_status' => $status,
            ]);

            return;
        }

        $state = self::STATE_MAP[$status];
        $label = $this->resolveLabel($status, $state, (int)$order->getStoreId());

        $this->applier->apply($order, $parsed, $state, $label, $payloadHash, $source);
        $this->logPositiveVerdict($order, $status, $payloadHash);
    }

    /**
     * Cancel a failed order that is still awaiting payment, once the get-status cron has confirmed
     * NovaPay still reports `failed` and the store's pending payment lifetime has passed (decided by
     * {@see \NovaPay\Payment\Cron\SyncStatus}, the only caller). Core's `sales_clean_expired_orders`
     * only cancels orders whose STATUS is `pending_payment`, so a novapay_failed order would otherwise
     * keep its stock reserved until NovaPay's session expires (up to ~30 days).
     *
     * A no-op (returns false) unless the stored NovaPay status is `failed` and the order state is
     * `pending_payment`. The order gets state `canceled` and label novapay_failed (still assigned to
     * `canceled`, so the admin keeps seeing "NovaPay Payment Failed"), or the `canceled` state's
     * default status when that assignment was removed. `novapay_status` stays `failed`: no NovaPay
     * event happened, and a later `holded`/`paid` for the still-open NovaPay session is then blocked
     * CRITICAL `money_taking_resurrection` by {@see blockReason()} for manual reconciliation.
     *
     * @param Order $order a freshly loaded order
     * @return bool whether the order was canceled
     */
    public function cancelStaleFailedOrder(Order $order): bool
    {
        if ($this->getStoredStatus($order) !== 'failed' || $order->getState() !== Order::STATE_PENDING_PAYMENT) {
            return false;
        }

        $canceledStatuses = $this->orderConfig->getStateStatuses(Order::STATE_CANCELED, false);
        $label = in_array(StatusCode::FAILED, $canceledStatuses, true)
            ? StatusCode::FAILED
            : (string)$this->orderConfig->getStateDefaultStatus(Order::STATE_CANCELED);

        return $this->applier->cancelStaleFailed($order, $label);
    }

    /**
     * Log a blocked status transition for `$reason` (see {@see blockReason()}) — CRITICAL for
     * `money_taking_resurrection` (NovaPay reports money taken or held on an order the merchant
     * already treated as canceled; a human must reconcile), WARNING for every other reason.
     *
     * @param Order $order
     * @param string $storedStatus
     * @param string $incomingStatus
     * @param string $reason
     * @return void
     */
    private function logBlockedTransition(
        Order $order,
        string $storedStatus,
        string $incomingStatus,
        string $reason
    ): void {
        $context = [
            'reason' => $reason,
            'event' => 'postback.blocked_transition',
            'order_increment_id' => $order->getIncrementId(),
            'stored_novapay_status' => $storedStatus,
            'incoming_novapay_status' => $incomingStatus,
            'order_state' => (string)$order->getState(),
        ];

        switch ($reason) {
            case 'money_taking_resurrection':
                $this->logger->critical(
                    'NovaPay postback rejected: money taken on a canceled order — NovaPay reports a '
                    . 'money-taking status for an order this merchant already treated as canceled. '
                    . 'This is not auto-resolvable; a human must reconcile '
                    . 'whether NovaPay actually charged the customer for a canceled order.',
                    $context
                );
                return;
            case 'order_closed':
                $message = 'NovaPay postback rejected: status transition blocked — the Magento order is '
                    . 'already canceled or closed and this incoming status would reopen it.';
                break;
            case 'stale_failed_after_hold':
                $message = 'NovaPay postback rejected: status transition blocked — a stale "failed" (an '
                    . 'earlier payment attempt in the same session) arrived after the funds were already held.';
                break;
            case 'rank_downgrade':
                $message = 'NovaPay postback rejected: status transition blocked — the incoming status is an '
                    . 'earlier NovaPay lifecycle status than the one the order already has (out-of-order '
                    . 'delivery; signed does not mean authorized to transition).';
                break;
            default:
                $message = 'NovaPay postback rejected: status transition blocked — the order already reached a '
                    . 'terminal-ish NovaPay status and this incoming status is not an allowed follow-up '
                    . '(signed does not mean authorized to transition).';
        }

        $this->logger->warning($message, $context);
    }

    /**
     * Whether `$parsed` repeats the order's stored session status.
     *
     * Same status and not a partial void. A fresh order (empty stored status) is never "unchanged".
     *
     * @param string $storedStatus
     * @param ParsedPostback $parsed
     * @return bool
     */
    private function isUnchangedStatus(string $storedStatus, ParsedPostback $parsed): bool
    {
        $status = $parsed->getStatus();
        if ($storedStatus === '' || $status !== $storedStatus) {
            return false;
        }

        return !($status === 'voided' && $this->isPartialVoid($parsed));
    }

    /**
     * INFO line for an event whose status the order already has (see {@see apply()}). Gated on
     * {@see Config::isLoggingEnabled()}.
     *
     * @param Order $order
     * @param string $status
     * @param string $source
     * @return void
     */
    private function logUnchanged(Order $order, string $status, string $source): void
    {
        if (!$this->config->isLoggingEnabled((int)$order->getStoreId())) {
            return;
        }

        $this->logger->info('NovaPay status unchanged; no order change applied.', [
            'event' => 'postback.unchanged',
            'order_id' => (int)$order->getEntityId(),
            'order_increment_id' => $order->getIncrementId(),
            'novapay_status' => $status,
            'source' => $source,
        ]);
    }

    /**
     * One INFO line on every status/event actually applied — order increment_id, the NovaPay
     * status just applied, and the payload's sha256 only; no PII. Gated on
     * {@see Config::isLoggingEnabled()}, same as the module's request/response logging.
     *
     * @param Order $order
     * @param string $status
     * @param string $payloadHash
     * @return void
     */
    private function logPositiveVerdict(Order $order, string $status, string $payloadHash): void
    {
        if (!$this->config->isLoggingEnabled((int)$order->getStoreId())) {
            return;
        }

        $this->logger->info('NovaPay postback applied successfully.', [
            'event' => 'postback.accepted',
            'order_increment_id' => $order->getIncrementId(),
            'novapay_status' => $status,
            'payload_sha256' => $payloadHash,
        ]);
    }

    /**
     * The order's currently stored `novapay_status` (i.e. the last *session-level* status applied by
     * {@see OrderStatusApplier::persistPaymentInfo()} — deliberately NOT touched by a partial-void
     * event, so a partially-refunded `paid`/`hold_confirmed` order is not mistaken for a fully
     * `voided` one by {@see blockReason()}), or `''` for a fresh order that has never had
     * one applied yet.
     *
     * @param Order $order
     * @return string
     */
    private function getStoredStatus(Order $order): string
    {
        $payment = $order->getPayment();
        if ($payment === null) {
            return '';
        }

        return trim((string)($payment->getAdditionalInformation(self::INFO_STATUS) ?? ''));
    }

    /**
     * Why `$incomingStatus` may NOT follow the order's `$storedStatus`, or `''` when it may.
     *
     * Layers, first match wins:
     *
     * 1. Closed order ({@see CLOSED_ORDER_STATES}), whatever the stored status: only
     *    {@see ALLOWED_ON_CLOSED_ORDER} passes on to the next layers. A blocked money-taking status
     *    is `money_taking_resurrection` unless the stored status is
     *    {@see SETTLED_STORED_STATUSES}; everything else is `order_closed`.
     * 2. {@see ALLOWED_FROM_STATUS}: a stored status present as a key there accepts only the listed
     *    incoming statuses: `money_taking_resurrection` from `failed`/`expired` into a money-taking
     *    status, else `terminal_status`.
     * 3. {@see FAILED_BLOCKED_FROM}: a `failed` after `holded` is `stale_failed_after_hold`.
     * 4. {@see LIFECYCLE_RANK}: both statuses ranked and the incoming rank lower: `rank_downgrade`.
     *
     * Everything else is allowed: an empty stored status (fresh order), forward progression, any
     * move into the unranked `expired`, and `failed` before the money is held.
     *
     * @param Order $order
     * @param string $storedStatus
     * @param string $incomingStatus
     * @return string
     */
    private function blockReason(Order $order, string $storedStatus, string $incomingStatus): string
    {
        $isMoneyTaking = in_array($incomingStatus, self::MONEY_TAKING_STATUSES, true);

        if (in_array((string)$order->getState(), self::CLOSED_ORDER_STATES, true)
            && !in_array($incomingStatus, self::ALLOWED_ON_CLOSED_ORDER, true)
        ) {
            return $isMoneyTaking && !in_array($storedStatus, self::SETTLED_STORED_STATUSES, true)
                ? 'money_taking_resurrection'
                : 'order_closed';
        }

        if (isset(self::ALLOWED_FROM_STATUS[$storedStatus])) {
            if (in_array($incomingStatus, self::ALLOWED_FROM_STATUS[$storedStatus], true)) {
                return '';
            }

            return $isMoneyTaking && in_array($storedStatus, self::CANCELED_STORED_STATUSES, true)
                ? 'money_taking_resurrection'
                : 'terminal_status';
        }

        if ($incomingStatus === 'failed' && in_array($storedStatus, self::FAILED_BLOCKED_FROM, true)) {
            return 'stale_failed_after_hold';
        }

        if (isset(self::LIFECYCLE_RANK[$storedStatus], self::LIFECYCLE_RANK[$incomingStatus])
            && self::LIFECYCLE_RANK[$incomingStatus] < self::LIFECYCLE_RANK[$storedStatus]
        ) {
            return 'rank_downgrade';
        }

        return '';
    }

    /**
     * Whether a `voided` postback/get-status response represents a single partial-refund operation
     * rather than the whole session being voided ("voided (single operation, partial) → stays
     * paid/completed").
     *
     * Decided by AMOUNTS, not merely by the presence of `operations[]` — a live `/v1/get-status`
     * response always includes one `operations[]` entry per NovaPay-side payment, even for a
     * single-line-item order's FULL refund (live-proven: order 000000040's full void carried
     * `operations: [{amount: "44.00", refunded_amount: null, status: "voided"}]` — treating "has
     * operations[]" as "is partial" left that order stuck `novapay_paid` forever instead of
     * transitioning to closed). Each operation's own `amount` is summed against its
     * `refunded_amount`; a void is partial only when that sum still leaves a remainder
     * (`sum(amount) - sum(refunded_amount) > `{@see AMOUNT_TOLERANCE}). `refunded_amount === null`
     * (as in the live full-void example above, and NovaPay's own documented v3 payload, which does
     * not define this field at all) is treated as "fully refunded" — i.e. equal to that operation's
     * own `amount` — since NovaPay only ever populates a non-null `refunded_amount` for an operation
     * that is genuinely still short of its full amount (confirmed by the documented `/v1/get-status`
     * two-operation partial-refund example: `{amount: "30.00", refunded_amount: 15}` +
     * `{amount: "20.00", refunded_amount: 15}`, top-level session `status` left as `paid`, not
     * `voided`, exactly matching "partial refunds do not change session status until fully
     * refunded"). No operation data at all (`null`/`[]`, e.g. a legacy v1/v2-shaped postback that
     * never carries `operations[]`) is a FULL void.
     *
     * @param ParsedPostback $parsed
     * @return bool
     */
    private function isPartialVoid(ParsedPostback $parsed): bool
    {
        $operations = $parsed->getOperations();
        if ($operations === null || $operations === []) {
            return false;
        }

        $totalAmount = 0.0;
        $totalRefunded = 0.0;

        foreach ($operations as $operation) {
            if (!is_array($operation)) {
                continue;
            }

            $amount = (float)($operation['amount'] ?? 0);
            $totalAmount += $amount;

            $refundedAmount = $operation['refunded_amount'] ?? null;
            $totalRefunded += $refundedAmount === null ? $amount : (float)$refundedAmount;
        }

        return ($totalAmount - $totalRefunded) > self::AMOUNT_TOLERANCE;
    }

    /**
     * Partial vs full refund for a `voided` event on an order with a paid invoice. NovaPay voids the
     * session after ANY partial refund of a single-operation session and sends no refund amounts in
     * the postback (NovaPay, 2026-09-28), so `voided` alone does not mean "fully refunded".
     *
     * Not applicable (returns false, the caller keeps the existing path): no paid amount (a released
     * hold, an unpaid order), or Magento's ledger already shows the whole paid amount refunded. No
     * NovaPay call is made in those cases.
     *
     * Otherwise NovaPay's refund state is read from the event's own `operations[]` when it carries
     * usable data (a get-status poll), else from `/v1/get-status` ({@see NovaPayRefundState::fetch()}):
     * - NovaPay reports everything refunded: full refund, returns false (closed / Refunded).
     * - NovaPay reports a partial refund: {@see OrderStatusApplier::applyPartialRefund()}, the order
     *   stays paid; flagged "outside Magento" when the ledger shows no refund at all.
     * - NovaPay could not be asked or reports nothing refunded (logged by the service): the ledger
     *   decides, a partial ledger refund stays paid, an empty ledger keeps the full-refund path.
     *
     * @param Order $order
     * @param ParsedPostback $parsed
     * @param string $payloadHash
     * @param string $source
     * @return bool whether the event was applied as a partial refund
     */
    private function applyPartialRefundIfConfirmed(
        Order $order,
        ParsedPostback $parsed,
        string $payloadHash,
        string $source
    ): bool {
        $paid = (float)$order->getBaseTotalPaid();
        $ledgerRefunded = (float)$order->getBaseTotalRefunded();
        if ($paid <= self::AMOUNT_TOLERANCE || $ledgerRefunded >= $paid - self::AMOUNT_TOLERANCE) {
            return false;
        }

        $snapshot = $this->refundState->fromOperations($parsed->getOperations())
            ?? $this->refundState->fetch($order, $parsed->getSessionId());

        if ($snapshot !== null && $snapshot->isFullyRefunded()) {
            return false;
        }

        if ($snapshot !== null && !$snapshot->hasRefund()) {
            $this->logger->warning(
                'NovaPay reports no refunded amount for a voided session; deciding from the Magento ledger.',
                [
                    'event' => 'refund.status_check_failed',
                    'order_increment_id' => $order->getIncrementId(),
                    'reason' => 'no refunded amount reported',
                ]
            );
            $snapshot = null;
        }

        if ($snapshot === null) {
            if ($ledgerRefunded <= self::AMOUNT_TOLERANCE) {
                return false;
            }
            $snapshot = new RefundSnapshot($paid, $ledgerRefunded);
        }

        $outsideMagento = $ledgerRefunded <= self::AMOUNT_TOLERANCE;
        $this->applier->applyPartialRefund($order, $parsed, $payloadHash, $snapshot, $outsideMagento, $source);

        return true;
    }

    /**
     * Resolve the status label to apply: the admin-configured one if it actually belongs to
     * `$state`, else the module's built-in default (logged).
     *
     * @param string $novapayStatus
     * @param string $state
     * @param int $storeId
     * @return string
     */
    private function resolveLabel(string $novapayStatus, string $state, int $storeId): string
    {
        $configured = $this->config->getMappedOrderStatus($novapayStatus, $storeId);
        $default = self::DEFAULT_LABEL[$novapayStatus];

        if ($configured === '') {
            return $default;
        }

        $validStatuses = $this->orderConfig->getStateStatuses($state, false);
        if (!in_array($configured, $validStatuses, true)) {
            $this->logger->warning(
                'NovaPay status mapping for "' . $novapayStatus . '" is not a valid status for its '
                . 'mapped order state; falling back to the built-in default.',
                [
                    'event' => 'config.status_label_invalid',
                    'configured_status' => $configured,
                    'state' => $state,
                    'default_status' => $default,
                ]
            );

            return $default;
        }

        return $configured;
    }
}
