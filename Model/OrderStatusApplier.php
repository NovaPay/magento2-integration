<?php
/**
 * Copyright © 2026 NovaPay. All rights reserved.
 * Licensed under the MIT License. See LICENSE for details.
 */
declare(strict_types=1);

namespace NovaPay\Payment\Model;

use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\DB\Transaction as DbTransaction;
use Magento\Framework\DB\TransactionFactory as DbTransactionFactory;
use Magento\Framework\Phrase;
use Magento\Framework\Serialize\Serializer\Json as JsonSerializer;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Sales\Api\Data\OrderPaymentInterface;
use Magento\Sales\Api\Data\OrderStatusHistoryInterface;
use Magento\Sales\Api\Data\TransactionInterface;
use Magento\Sales\Api\InvoiceRepositoryInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Api\TransactionRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\Order\Item as OrderItem;
use Magento\Sales\Model\Order\Payment;
use Magento\Sales\Model\Order\Payment\Transaction;
use Magento\Sales\Model\Service\InvoiceService;
use NovaPay\Payment\Gateway\Config;
use NovaPay\Payment\Model\Order\AdminPhraseRenderer;
use NovaPay\Payment\Model\Postback\ParsedPostback;
use NovaPay\Payment\Model\Refund\RefundSnapshot;
use Psr\Log\LoggerInterface;

/**
 * Executes the native Magento side effects for each NovaPay status and is the sole place that
 * actually writes order/payment state to the database — {@see StatusMapper} resolves *what* to
 * apply (state + label), this class applies it. Only ever called by {@see StatusMapper::apply()}.
 *
 * Uses native Magento mechanisms throughout: InvoiceService + `Invoice::CAPTURE_OFFLINE` (funds are
 * already captured/authorized at NovaPay by the time a postback arrives), `Payment::addTransaction()`
 * for the payment ledger, `Order::cancel()` for expired/never-captured-hold-cancel. `failed` has no
 * side effect: the customer may still retry within the same NovaPay session, so the order only moves
 * to `pending_payment` (stock reservation kept, like any order awaiting payment).
 *
 * Every side effect below is executed BEFORE {@see apply()} mutates the order's state/status — the
 * native `can*()` guards (`canCancel()`, `canInvoice()`) must see the order's *pre-transition*
 * state, otherwise they are trivially and permanently false. `apply()` performs the state/label
 * mutation and the single final save, once, after the side effect has already run and persisted.
 *
 * @api
 */
class OrderStatusApplier
{
    private const INFO_STATUS = 'novapay_status';
    private const INFO_PAYTYPE = 'novapay_paytype';
    private const INFO_RRN = 'novapay_rrn';
    private const INFO_APPROVAL = 'novapay_approval';
    private const INFO_CARD_PAN = 'novapay_card_pan';
    private const INFO_CARD_BANK = 'novapay_card_bank';
    private const INFO_CARD_COUNTRY = 'novapay_card_country';
    private const INFO_CARD_TYPE = 'novapay_card_type';
    private const INFO_LAST_AT = 'novapay_last_postback_at';
    private const INFO_REFUNDED_OPERATIONS = 'novapay_refunded_operations';

    /**
     * The `operations[0].transaction_id` of the most recently applied `paid`/`hold_confirmed` event
     * — see {@see persistCaptureOperationId()}. After a partial complete-hold, NovaPay issues a NEW
     * transaction_id for the (now-partially-captured) operation, distinct from the original
     * `/v1/add-payment` response id ({@see \NovaPay\Payment\Model\CheckoutOrchestrator}'s
     * `novapay_payment_id`, set once at checkout and never updated afterwards).
     * {@see \NovaPay\Payment\Gateway\Command\RefundCommand} must use THIS id (when present) for a
     * partial refund's `operations[].id`, or NovaPay will reject the request as referencing a
     * stale/already-superseded operation.
     */
    private const INFO_CAPTURE_OPERATION_ID = 'novapay_capture_operation_id';

    /**
     * Idempotency flag for {@see noteReleasedHoldRemainderIfNeeded()} — a repeat `paid`/
     * `hold_confirmed` event for the same order must never add the "hold remainder released"
     * status-history comment a second time.
     */
    private const INFO_PARTIAL_CAPTURE_NOTICE_ADDED = 'novapay_partial_capture_notice_added';

    /**
     * Idempotency flag for {@see cancelReleasedHoldRemainder()}: the uninvoiced quantity of a
     * partially captured hold is canceled once. A repeat `paid`/`hold_confirmed` event must never
     * cancel (or re-dispatch the item cancel event for) the same items a second time.
     */
    private const INFO_REMAINDER_CANCELED = 'novapay_remainder_canceled';

    /**
     * Idempotency key for {@see applyPartialRefund()}: the refunded amount its admin comment was
     * last added for. A re-evaluated `voided` with the same amount adds no second comment.
     */
    private const INFO_PARTIAL_REFUND_CONFIRMED_AMOUNT = 'novapay_partial_refund_confirmed_amount';

    /**
     * The raw NovaPay event `status` of the most recently processed postback/get-status response,
     * regardless of whether it was a session-level transition or a partial-void event — audit-only,
     * never read by {@see StatusMapper}. Contrast with {@see INFO_STATUS} (`novapay_status`), the
     * session-level status {@see StatusMapper}'s progression guard reads, which a partial-void
     * event must never overwrite (see {@see persistPaymentInfo()}).
     */
    private const INFO_LAST_EVENT = 'novapay_last_event';

    /**
     * Bounded replay-window: a single "last hash" field only detects a repeat of the *immediately
     * preceding* postback — an older, still-validly-signed payload replayed out of order would
     * otherwise be re-applied, double-counting effects such as a partial refund. The last
     * {@see MAX_STORED_HASHES} sha256 hashes are kept, and {@see hasProcessedHash()} checks
     * membership in the whole list, not just the newest entry.
     */
    private const INFO_HASHES = 'novapay_postback_hashes';
    private const MAX_STORED_HASHES = 20;

    /**
     * Float-rounding tolerance for two reconciliation checks: `base_total_paid` vs
     * `base_total_refunded` in {@see applyVoided()}, and NovaPay's reported paid amount vs the
     * order's `base_grand_total` in {@see guardAgainstPaidAmountMismatch()}.
     */
    private const RECONCILE_TOLERANCE = 0.01;

    /**
     * Event sources shown in the order history entry: a NovaPay postback, or the get-status cron
     * fallback ({@see \NovaPay\Payment\Cron\SyncStatus}), which runs the same pipeline.
     */
    public const SOURCE_POSTBACK = 'postback';
    public const SOURCE_GET_STATUS = 'get-status';

    /**
     * Printed in the order history entry for a value the event did not carry.
     */
    private const HISTORY_MISSING_VALUE = '-';

    /**
     * @param InvoiceService $invoiceService
     * @param DbTransactionFactory $dbTransactionFactory
     * @param OrderRepositoryInterface $orderRepository
     * @param InvoiceRepositoryInterface $invoiceRepository
     * @param JsonSerializer $json
     * @param DateTime $dateTime
     * @param LoggerInterface $logger
     * @param Config $config
     * @param TransactionRepositoryInterface $transactionRepository
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param AdminPhraseRenderer $adminPhraseRenderer renders order comments in the admin locale
     */
    public function __construct(
        private readonly InvoiceService $invoiceService,
        private readonly DbTransactionFactory $dbTransactionFactory,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly InvoiceRepositoryInterface $invoiceRepository,
        private readonly JsonSerializer $json,
        private readonly DateTime $dateTime,
        private readonly LoggerInterface $logger,
        private readonly Config $config,
        private readonly TransactionRepositoryInterface $transactionRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly AdminPhraseRenderer $adminPhraseRenderer
    ) {
    }

    /**
     * INFO-level "nothing to do, already applied"-style lines, gated on
     * {@see Config::isLoggingEnabled()} — same rule as {@see \NovaPay\Payment\Model\StatusMapper}'s
     * own INFO logging. WARNING-and-above lines in this class are unconditional and log directly.
     *
     * @param Order $order
     * @param string $message
     * @param array $context
     * @return void
     */
    private function logInfo(Order $order, string $message, array $context = []): void
    {
        if (!$this->config->isLoggingEnabled((int)$order->getStoreId())) {
            return;
        }

        $this->logger->info($message, $context);
    }

    /**
     * Whether `$payloadHash` is already present in `$order`'s bounded postback-hash history — read
     * by {@see \NovaPay\Payment\Model\PostbackProcessor} before this class (or {@see StatusMapper})
     * is ever invoked, so a replayed-out-of-order postback never reaches a side effect at all.
     *
     * @param Order $order
     * @param string $payloadHash
     * @return bool
     */
    public function hasProcessedHash(Order $order, string $payloadHash): bool
    {
        $payment = $order->getPayment();
        if ($payment === null) {
            return false;
        }

        $hashes = $this->decodeJsonArray($payment->getAdditionalInformation(self::INFO_HASHES));

        return in_array($payloadHash, $hashes, true);
    }

    /**
     * Append `$payloadHash` to the bounded postback-hash history and save, without touching order
     * state/status, the payment's other additional_information, or the order history. Used by
     * {@see StatusMapper::apply()} for an event whose status the order already has, so a
     * byte-identical repeat of it is later caught as a duplicate before reaching StatusMapper.
     *
     * @param Order $order
     * @param string $payloadHash
     * @return void
     */
    public function recordPayloadHash(Order $order, string $payloadHash): void
    {
        $payment = $order->getPayment();
        if ($payment === null || $this->hasProcessedHash($order, $payloadHash)) {
            return;
        }

        $this->appendPayloadHash($order, $payloadHash);
        $this->orderRepository->save($order);
    }

    /**
     * Apply the resolved state/label for a non-transient, non-partial-void status, plus its side
     * effect. The side effect always runs first, against the order's state as StatusMapper found
     * it — see the class docblock for why the ordering is load-bearing.
     *
     * An admin-only order history entry describing the applied event is added right before the
     * final save (see {@see addEventHistoryEntry()}).
     *
     * @param Order $order
     * @param ParsedPostback $parsed
     * @param string $state
     * @param string $label
     * @param string $payloadHash
     * @param string $source where the event came from: {@see SOURCE_POSTBACK} or {@see SOURCE_GET_STATUS}
     * @return void
     */
    public function apply(
        Order $order,
        ParsedPostback $parsed,
        string $state,
        string $label,
        string $payloadHash,
        string $source = self::SOURCE_POSTBACK
    ): void {
        $this->persistPaymentInfo($order, $parsed, $payloadHash);

        switch ($parsed->getStatus()) {
            case 'paid':
                $this->applyPaid($order, $parsed, $label);
                break;
            case 'holded':
                $this->applyHolded($order, $parsed);
                break;
            case 'hold_confirmed':
                $this->applyHoldConfirmed($order, $parsed, $label);
                break;
            case 'voided':
                $this->applyVoided($order);
                break;
            case 'expired':
                $this->applyExpired($order, $parsed);
                break;
            default:
                // created / processing / failed: no further side effect. `failed` deliberately does
                // not cancel: NovaPay lets the customer retry within the same session, and a later
                // holded / paid / hold_confirmed for that session must still apply.
        }

        $order->setState($state);
        $order->setStatus($label);
        $this->addEventHistoryEntry($order, $parsed, $label, $source);
        $this->orderRepository->save($order);
    }

    /**
     * Partial-refund void ("voided (single operation, partial)" → stays paid/completed).
     *
     * Order state/status is left untouched — only per-line refunded amounts are recorded, deduped
     * by operation id (see {@see extractOperationId()}): a replayed-but-not-hash-identical postback
     * for the same underlying refund operation REPLACES that operation's ledger entry in place
     * instead of appending a second one. This replace-not-add behavior is intentional and required:
     * the `refund_amount` recorded for each operation (see {@see extractOperationRefundedAmount()})
     * is NovaPay's own CUMULATIVE refunded amount for that operation as of the triggering event — the
     * running total refunded so far, not a per-event delta to be summed across postbacks — matching
     * how NovaPay's documented `/v1/get-status` `refunded_amount` field itself behaves (its two-op
     * partial-refund example shows a running total per operation, not an incremental amount). Simply
     * summing successive events for the same id would double-count; replacing with the latest value
     * is correct. `novapay_status` (the session-level status {@see StatusMapper}'s progression guard
     * reads) is likewise left untouched (see {@see persistPaymentInfo()}'s `$isSessionStatus`
     * parameter): a partial void must never be mistaken for the whole session having been voided.
     *
     * The STORED ledger's own shape (`{id, refund_amount, recorded_at}`, read by
     * {@see \NovaPay\Payment\Block\Adminhtml\Order\View\NovaPayInfo::getRefundedOperations()} — whose
     * admin column is labeled "Refunded Amount (Cumulative)" for the same reason — and by
     * {@see \NovaPay\Payment\Gateway\Command\RefundCommand::assertNoPriorRefund()}, which only checks
     * for non-emptiness) is unchanged by this method — only how the incoming, already-parsed
     * `operations[]` entry (see {@see extractOperationId()}/{@see extractOperationRefundedAmount()})
     * is read to populate it.
     *
     * An admin-only order history entry describing the event is added before the save, stamped
     * with the order's current (unchanged) status.
     *
     * @param Order $order
     * @param ParsedPostback $parsed
     * @param string $payloadHash
     * @param string $source where the event came from: {@see SOURCE_POSTBACK} or {@see SOURCE_GET_STATUS}
     * @return void
     */
    public function applyPartialVoid(
        Order $order,
        ParsedPostback $parsed,
        string $payloadHash,
        string $source = self::SOURCE_POSTBACK
    ): void {
        $this->persistPaymentInfo($order, $parsed, $payloadHash, false);
        $this->recordRefundedOperations($order, $parsed->getOperations() ?? []);
        $this->addEventHistoryEntry($order, $parsed, (string)$order->getStatus(), $source);
        $this->orderRepository->save($order);
    }

    /**
     * `voided` for an invoiced order that NovaPay (or, when NovaPay could not be asked, Magento's
     * ledger) shows only partially refunded. NovaPay voids the session after any partial refund of a
     * single-operation session and sends no refund amounts, so {@see StatusMapper} decides partial vs
     * full from `/v1/get-status` and the ledger, and calls this method for the partial case.
     *
     * Same persistence as {@see applyPartialVoid()}: no state/status change, `novapay_status` stays
     * as stored (`paid` / `hold_confirmed`), `novapay_last_event` = `voided`, payload hash recorded,
     * NovaPay's operations recorded in the refunded-operations ledger (which also makes the refund
     * form read-only: further refunds go through NovaPay support).
     *
     * Adds ONE admin comment instead of the generic per-event entry:
     * - `$outsideMagento` false: "NovaPay confirmed a partial refund ...";
     * - `$outsideMagento` true (no credit memo in Magento): "... made outside Magento ... reconcile
     *   manually", plus a WARNING `refund.partial_outside_magento`. No credit memo is created.
     *
     * Idempotent via {@see INFO_PARTIAL_REFUND_CONFIRMED_AMOUNT}: a re-evaluated `voided` (another
     * postback body or a get-status poll) with the same refunded amount adds no second comment.
     *
     * @param Order $order
     * @param ParsedPostback $parsed
     * @param string $payloadHash
     * @param RefundSnapshot $snapshot refunded vs captured amount
     * @param bool $outsideMagento whether Magento's ledger shows no refund at all
     * @param string $source where the event came from: {@see SOURCE_POSTBACK} or {@see SOURCE_GET_STATUS}
     * @return void
     */
    public function applyPartialRefund(
        Order $order,
        ParsedPostback $parsed,
        string $payloadHash,
        RefundSnapshot $snapshot,
        bool $outsideMagento,
        string $source = self::SOURCE_POSTBACK
    ): void {
        $this->persistPaymentInfo($order, $parsed, $payloadHash, false);
        $this->recordRefundedOperations($order, $snapshot->getOperations());

        /** @var Payment $payment */
        $payment = $order->getPayment();
        $refunded = number_format($snapshot->getRefunded(), 2, '.', '');
        $isNew = (string)$payment->getAdditionalInformation(self::INFO_PARTIAL_REFUND_CONFIRMED_AMOUNT) !== $refunded;

        if ($isNew) {
            $currency = (string)$order->getBaseCurrencyCode();
            $currency = $currency !== '' ? $currency : 'UAH';
            $phrase = $outsideMagento
                ? __(
                    'NovaPay reports a partial refund of %1 %2 made outside Magento. No credit memo was '
                    . 'created; please reconcile manually.',
                    $refunded,
                    $currency
                )
                : __(
                    'NovaPay confirmed a partial refund: %1 of %2 %3 refunded, the rest remains captured.',
                    $refunded,
                    number_format($snapshot->getAmount(), 2, '.', ''),
                    $currency
                );
            $this->addAdminComment($order, $phrase, (string)$order->getStatus());
            $payment->setAdditionalInformation(self::INFO_PARTIAL_REFUND_CONFIRMED_AMOUNT, $refunded);
        }

        $context = [
            'order_increment_id' => $order->getIncrementId(),
            'refunded_amount' => $snapshot->getRefunded(),
            'captured_amount' => $snapshot->getAmount(),
            'source' => $source,
            'comment_added' => $isNew,
        ];
        if ($outsideMagento) {
            $this->logger->warning(
                'NovaPay reports a partial refund with no credit memo in Magento; the order stays paid, '
                . 'reconcile manually.',
                ['event' => 'refund.partial_outside_magento'] + $context
            );
        } else {
            $this->logInfo(
                $order,
                'NovaPay confirmed a partial refund; the order stays paid.',
                ['event' => 'postback.partial_refund_confirmed'] + $context
            );
        }

        $this->orderRepository->save($order);
    }

    /**
     * Records NovaPay's per-operation refunded amounts in `novapay_refunded_operations`.
     *
     * Replaces an existing entry with the same operation id (see {@see applyPartialVoid()}). Does not save.
     *
     * @param Order $order
     * @param array $operations raw `operations[]` entries
     * @return void
     */
    private function recordRefundedOperations(Order $order, array $operations): void
    {
        $payment = $order->getPayment();
        $stored = $payment->getAdditionalInformation(self::INFO_REFUNDED_OPERATIONS);
        $operationsById = $this->indexRefundedOperationsById($this->decodeJsonArray($stored));

        foreach ($operations as $operation) {
            if (!is_array($operation)) {
                continue;
            }

            $id = $this->extractOperationId($operation);
            if ($id === '') {
                continue;
            }

            $operationsById[$id] = [
                'id' => $id,
                'refund_amount' => $this->extractOperationRefundedAmount($operation),
                'recorded_at' => $this->dateTime->gmtDate(),
            ];
        }

        $payment->setAdditionalInformation(
            self::INFO_REFUNDED_OPERATIONS,
            $this->json->serialize(array_values($operationsById))
        );
    }

    /**
     * The id to key a ledger entry by for one `operations[]` entry. The real, live-confirmed
     * `/v1/get-status` (and postback) operation shape is `{transaction_id, external_id, amount,
     * refunded_amount, status}`; `id` is only kept as a fallback for the earlier, unconfirmed
     * `{id, refund_amount}` shape assumption (still used by e.g. `qa/fixtures/
     * generate-postback-fixtures.php`), in case any existing fixture/integration still sends it.
     *
     * Guarded with {@see is_scalar()} before the `(string)` cast: a malformed payload carrying e.g.
     * `transaction_id` as an array (rather than throwing a PHP "Array to string conversion" warning
     * and corrupting the ledger) yields `''`, which {@see applyPartialVoid()}'s caller already skips.
     *
     * @param array $operation
     * @return string
     */
    private function extractOperationId(array $operation): string
    {
        $candidate = $operation['transaction_id'] ?? $operation['id'] ?? '';

        return is_scalar($candidate) ? (string)$candidate : '';
    }

    /**
     * The refunded amount to record for one `operations[]` entry.
     *
     * Real shape: `refunded_amount` present (possibly `null`) — a `null` value means this operation
     * is fully refunded, consistent with {@see \NovaPay\Payment\Model\StatusMapper::isPartialVoid()},
     * so this operation's own `amount` is recorded instead; a non-null value is recorded as-is.
     * Legacy shape: no `refunded_amount` key at all — falls back to the earlier `refund_amount`
     * field name, then `amount`, matching this method's pre-fix behavior for backward compatibility.
     *
     * Every value read here is guarded with {@see is_scalar()} before its `(float)` cast — an
     * array-typed `refunded_amount`/`refund_amount`/`amount` is treated as absent (falls through to
     * the next candidate, ultimately `0.0`) rather than triggering a PHP cast warning.
     *
     * @param array $operation
     * @return float
     */
    private function extractOperationRefundedAmount(array $operation): float
    {
        if (array_key_exists('refunded_amount', $operation)) {
            $refundedAmount = $operation['refunded_amount'];

            return $refundedAmount !== null && is_scalar($refundedAmount)
                ? (float)$refundedAmount
                : $this->extractOperationAmount($operation);
        }

        $legacyRefundAmount = $operation['refund_amount'] ?? null;

        return is_scalar($legacyRefundAmount)
            ? (float)$legacyRefundAmount
            : $this->extractOperationAmount($operation);
    }

    /**
     * An `operations[]` entry's own `amount`.
     *
     * Guarded with {@see is_scalar()} before the `(float)` cast — see
     * {@see extractOperationRefundedAmount()}.
     *
     * @param array $operation
     * @return float
     */
    private function extractOperationAmount(array $operation): float
    {
        $amount = $operation['amount'] ?? null;

        return is_scalar($amount) ? (float)$amount : 0.0;
    }

    /**
     * `paid` (direct mode): funds already captured by NovaPay — auto-create a paid invoice with an
     * offline capture case (no gateway call: there is nothing left to capture) and record a closed
     * capture transaction so the payment ledger stays coherent.
     *
     * Idempotent: an order that already has a paid/closed invoice and no open one is left with its
     * existing invoice(s) — this method never creates a second one.
     *
     * Live-evidence divergence (see {@see finalizePendingInvoice()}): a hold captured via the admin
     * "Confirm hold" action leaves an OPEN (pending) invoice waiting for confirmation. NovaPay's
     * documented status list says that confirmation arrives as `hold_confirmed`, but on the test
     * merchant it has been live-observed arriving as `paid` instead (orders 000000044, 000000046) —
     * so this method must try to finalize a pending invoice FIRST, before treating "already has an
     * invoice" as "already fully applied, nothing to do".
     *
     * Guarded by {@see Order::canInvoice()} (mirroring {@see applyHoldConfirmed()}'s own
     * fallback-path guard): {@see StatusMapper}'s progression guard is the primary defense against
     * a `paid` postback reaching a canceled order, but this is defense-in-depth for the case where
     * the order was canceled by some other means before ever recording a terminal `novapay_status`.
     *
     * Also guarded by {@see guardAgainstPaidAmountMismatch()}: this fallback path invoices the
     * order's FULL `base_grand_total` — it must never do so when NovaPay's own reported amount for
     * this event says less was actually captured.
     *
     * The offline-capture fallback also closes an open authorization via
     * {@see closeAuthorization()} (live evidence, orders 000000072/000000073/000000081): a hold
     * confirmed on NovaPay's side (not through a Magento "Capture Online" invoice) arrives here as
     * `paid` with no pending invoice, after {@see applyHolded()} already recorded an open
     * authorization. Without closing it, `Order::canVoidPayment()` stays true on the fully captured
     * (even later refunded) order and the admin keeps offering a Void button whose click records a
     * bogus void transaction. A direct-mode `paid` order has no authorization — the helper no-ops.
     *
     * @param Order $order
     * @param ParsedPostback $parsed
     * @param string $targetStatus the status label {@see apply()} sets right after this side effect
     * @return void
     */
    private function applyPaid(Order $order, ParsedPostback $parsed, string $targetStatus): void
    {
        $this->persistCaptureOperationId($order, $parsed);

        if ($this->finalizePendingInvoice($order, $parsed, $targetStatus)) {
            return;
        }

        if ($order->getInvoiceCollection()->getSize() > 0) {
            $this->logInfo(
                $order,
                'NovaPay "paid" postback received for an order that already has an invoice; skipping.',
                ['event' => 'sideeffect.paid_already_invoiced', 'order_increment_id' => $order->getIncrementId()]
            );

            return;
        }

        if (!$order->canInvoice()) {
            $this->logger->warning(
                'NovaPay "paid" postback received for an order that cannot be invoiced '
                . '(canInvoice() is false — e.g. already canceled); applying the status label only.',
                ['event' => 'sideeffect.paid_cannot_invoice', 'order_increment_id' => $order->getIncrementId()]
            );

            return;
        }

        if ($this->guardAgainstPaidAmountMismatch($order, $parsed, $targetStatus)) {
            return;
        }

        /** @var Payment $payment */
        $payment = $order->getPayment();
        $invoice = $this->invoiceService->prepareInvoice($order);
        $invoice->setRequestedCaptureCase(Invoice::CAPTURE_OFFLINE);
        $payment->setTransactionId($this->buildTransactionId($parsed, 'capture'));
        $payment->setIsTransactionClosed(1);

        $invoice->register();
        $payment->addTransaction(Transaction::TYPE_CAPTURE, $invoice, true);
        $order->setIsInProcess(true);

        $dbTransaction = $this->dbTransactionFactory->create()
            ->addObject($invoice)
            ->addObject($order);
        $this->closeAuthorization($order, $payment, $dbTransaction);
        $dbTransaction->save();
    }

    /**
     * `holded`: funds blocked at NovaPay — record an open authorization transaction. No invoice yet
     * (created later, by the admin "confirm hold" action).
     *
     * Guarded by the same {@see Order::canInvoice()}-style defensive check as {@see applyPaid()}:
     * an authorization transaction must never be attached to an order that cannot be invoiced
     * (e.g. already canceled).
     *
     * @param Order $order
     * @param ParsedPostback $parsed
     * @return void
     */
    private function applyHolded(Order $order, ParsedPostback $parsed): void
    {
        /** @var Payment $payment */
        $payment = $order->getPayment();

        if ($payment->getAuthorizationTransaction()) {
            $this->logInfo(
                $order,
                'NovaPay "holded" postback received for an order that already has an authorization '
                . 'transaction; updating status only.',
                ['event' => 'sideeffect.holded_already_authorized', 'order_increment_id' => $order->getIncrementId()]
            );

            return;
        }

        if (!$order->canInvoice()) {
            $this->logger->warning(
                'NovaPay "holded" postback received for an order that cannot be invoiced '
                . '(canInvoice() is false — e.g. already canceled); refusing to attach an '
                . 'authorization transaction. Applying the status label only.',
                ['event' => 'sideeffect.holded_cannot_invoice', 'order_increment_id' => $order->getIncrementId()]
            );

            return;
        }

        $payment->setTransactionId($this->buildTransactionId($parsed, 'auth'));
        $payment->setIsTransactionClosed(0);
        $payment->addTransaction(Transaction::TYPE_AUTH, null, true);
    }

    /**
     * Money-risk guard for the "no invoice yet, create a fresh one for the full order amount"
     * fallback paths in {@see applyPaid()} and {@see applyHoldConfirmed()}: refuses to fabricate an
     * invoice for the order's full `base_grand_total` when NovaPay's own reported amount for this
     * event says otherwise — e.g. NovaPay only actually captured part of the order (a partial
     * complete-hold done outside this admin session, or completed via a direct API call bypassing
     * the module — live-proven possible, see
     * {@see \NovaPay\Payment\Gateway\Request\CompleteHoldBuilder}'s class docblock). Silently
     * invoicing the full amount in that situation would misrepresent how much was actually paid.
     *
     * Compares {@see ParsedPostback::getAmount()} against `$order->getBaseGrandTotal()` within
     * {@see RECONCILE_TOLERANCE}. When they differ beyond tolerance: logs CRITICAL (event
     * `novapay.paid_amount_mismatch`, both amounts, no PII) and adds an admin-only order status
     * history comment asking a human to reconcile and invoice the actually-captured amount offline
     * — then returns `true` so the caller skips invoice creation entirely (the order's state/status
     * label still transitions normally via {@see apply()}'s own save, same as every other side
     * effect in this class).
     *
     * When NovaPay's amount is unavailable (`null` — the event carried no `amount` at all) this is
     * NOT treated as a mismatch: existing behavior (create the fallback invoice) is preserved,
     * since there is nothing to compare against and this fallback path already only exists for the
     * abnormal case of a postback/cron arriving before the admin's own action ran.
     *
     * @param Order $order
     * @param ParsedPostback $parsed
     * @param string $targetStatus the status label the order-history comment is stamped with
     * @return bool true if a mismatch was found and logged (caller must skip invoice creation)
     */
    private function guardAgainstPaidAmountMismatch(Order $order, ParsedPostback $parsed, string $targetStatus): bool
    {
        $reportedAmount = $parsed->getAmount();
        if ($reportedAmount === null) {
            return false;
        }

        $orderTotal = (float)$order->getBaseGrandTotal();
        if (abs($reportedAmount - $orderTotal) <= self::RECONCILE_TOLERANCE) {
            return false;
        }

        $this->logAndCommentPaidAmountMismatch($order, $parsed, $reportedAmount, $orderTotal, $targetStatus);

        return true;
    }

    /**
     * Shared log + admin status-history comment for a NovaPay-reported amount that does not match
     * an expected amount — used by both {@see guardAgainstPaidAmountMismatch()} (expected = the
     * order's full `base_grand_total`) and {@see finalizePendingInvoice()} (expected = the pending
     * invoice's own `base_grand_total`, i.e. what was actually requested to be captured).
     *
     * Logs CRITICAL (event `novapay.paid_amount_mismatch`, both amounts, no PII) and adds an
     * admin-only (`$isVisibleOnFront = false`) order status history comment asking a human to
     * reconcile and invoice the actually-captured amount manually.
     *
     * @param Order $order
     * @param ParsedPostback $parsed
     * @param float $reportedAmount NovaPay's reported amount for this event
     * @param float $expectedAmount the amount it was compared against
     * @param string $targetStatus the status label the order-history comment is stamped with
     * @return void
     */
    private function logAndCommentPaidAmountMismatch(
        Order $order,
        ParsedPostback $parsed,
        float $reportedAmount,
        float $expectedAmount,
        string $targetStatus
    ): void {
        $this->logger->critical(
            'NovaPay reported a paid amount that does not match the expected amount; refusing to '
            . 'auto-finalize/create an invoice for the mismatched amount, since NovaPay may only '
            . 'have captured part of what was expected. A human must reconcile and create the '
            . 'invoice manually for the amount NovaPay actually captured.',
            [
                'event' => 'novapay.paid_amount_mismatch',
                'order_increment_id' => $order->getIncrementId(),
                'novapay_status' => $parsed->getStatus(),
                'novapay_reported_amount' => $reportedAmount,
                'expected_amount' => $expectedAmount,
            ]
        );

        $this->addAdminComment(
            $order,
            __(
                'NovaPay reported a paid amount of %1 that does not match this order\'s total of %2. '
                . 'No invoice was created automatically — please reconcile with NovaPay and create '
                . 'the invoice manually for the amount actually captured.',
                number_format($reportedAmount, 2, '.', ''),
                number_format($expectedAmount, 2, '.', '')
            ),
            $targetStatus
        );
    }

    /**
     * Adds an admin-only order status history comment written by this module.
     *
     * The text is rendered in the admin interface language (default-scope `general/locale/code`)
     * by {@see AdminPhraseRenderer}: postbacks and the get-status cron run in a storefront/cron
     * context whose locale (e.g. a store view's) is not the admin's. The phrase goes through `__()`
     * at the call site so it stays in the i18n CSVs.
     *
     * The comment is stamped with `$targetStatus`, the status label {@see apply()} sets right after
     * the side effect, instead of the order's pre-transition status (e.g. `payment_review`). The
     * history record's own status is set directly, so the order's status is not changed early and
     * the comment is still persisted by the same save as before.
     *
     * Every comment is admin-only (`is_visible_on_front` = 0) and marked as not customer-notified
     * (`is_customer_notified` = 0).
     *
     * @param Order $order
     * @param Phrase $phrase
     * @param string $targetStatus
     * @return void
     */
    private function addAdminComment(Order $order, Phrase $phrase, string $targetStatus): void
    {
        $history = $order->addCommentToStatusHistory($this->adminPhraseRenderer->render($phrase));
        if (!$history instanceof OrderStatusHistoryInterface) {
            return;
        }

        $history->setIsCustomerNotified(0);
        if ($targetStatus !== '') {
            $history->setStatus($targetStatus);
        }
    }

    /**
     * Adds the order history entry for an applied NovaPay event (postback or get-status): the
     * NovaPay status, the source, amount, RRN, paytype and NovaPay's `created_at`, labeled "session
     * created": in a get-status response it is the session creation time, not the event time, so it
     * is labeled the same way for both sources rather than presented as the event time. A value
     * the event did not carry is printed as {@see HISTORY_MISSING_VALUE}. Admin-only and not
     * customer-notified, rendered in the admin locale and stamped with `$targetStatus` through
     * {@see addAdminComment()}.
     *
     * Only reached for events that are actually applied: duplicates, transient statuses and blocked
     * transitions are stopped before {@see apply()} / {@see applyPartialVoid()}.
     *
     * @param Order $order
     * @param ParsedPostback $parsed
     * @param string $targetStatus
     * @param string $source
     * @return void
     */
    private function addEventHistoryEntry(
        Order $order,
        ParsedPostback $parsed,
        string $targetStatus,
        string $source
    ): void {
        $amount = $parsed->getAmount();
        $currency = (string)$order->getBaseCurrencyCode();

        $this->addAdminComment(
            $order,
            __(
                'NovaPay: status %1 received (%2). Amount %3 %4, RRN %5, paytype %6, session created %7.',
                $parsed->getStatus(),
                $source,
                $amount !== null ? number_format($amount, 2, '.', '') : self::HISTORY_MISSING_VALUE,
                $currency !== '' ? $currency : 'UAH',
                $parsed->getRrn() ?? self::HISTORY_MISSING_VALUE,
                $parsed->getPaytype() ?? self::HISTORY_MISSING_VALUE,
                $parsed->getCreatedAt() ?? self::HISTORY_MISSING_VALUE
            ),
            $targetStatus
        );
    }

    /**
     * Finalize an already-created OPEN (pending) invoice as paid — native `Invoice::pay()`, which
     * (via Magento core) also sets `Order::setTotalPaid()`. This is the shared "a hold's capture
     * was just confirmed" side effect for BOTH {@see applyHoldConfirmed()} (the documented status)
     * and {@see applyPaid()} (see the live-evidence note below), so whichever one NovaPay actually
     * sends, the pending invoice created by the admin "Confirm hold" ("Capture Online") action —
     * left `Invoice::STATE_OPEN` by {@see \NovaPay\Payment\Gateway\Command\CaptureCommand} — gets
     * paid instead of sitting open forever.
     *
     * Live-evidence divergence: NovaPay's documented session-status list treats `hold_confirmed`
     * (a completed hold) and `paid` (a direct, non-hold capture) as distinct terminal statuses —
     * see docs/novapay-api-reference.md. On the NovaPay test environment (merchant_id 2), however,
     * a completed hold's `/v1/get-status`/postback `status` has been live-observed as `paid`, NOT
     * `hold_confirmed` (order 000000044, full complete-hold; order 000000046, partial complete-hold
     * — see {@see \NovaPay\Payment\Gateway\Request\CompleteHoldBuilder}'s class docblock for the
     * matching complete-hold-request fix). Before this method existed, a `paid` postback arriving
     * for such an order hit {@see applyPaid()}'s "already has an invoice; skipping" branch and never
     * finalized the pending invoice — leaving it `Invoice::STATE_OPEN` and `Order::total_paid` NULL
     * forever, even though the invoice's own admin grid status showed "Paid" (a display default, not
     * the actual invoice state).
     *
     * Money-risk guard: before paying, compares {@see ParsedPostback::getAmount()} (when present)
     * against the pending invoice's OWN `base_grand_total` (i.e. what was actually requested to be
     * captured — not the order's full total, which may legitimately be larger for a deliberate
     * partial capture). A mismatch means NovaPay's own report of what it captured for this specific
     * event disagrees with what Magento already invoiced — logs + comments via
     * {@see logAndCommentPaidAmountMismatch()} and returns `true` WITHOUT calling `pay()`, so the
     * invoice is left `Invoice::STATE_OPEN` (the current, safe state) rather than incorrectly marked
     * paid; the caller must NOT fall through to its own fallback invoice-creation path either, since
     * a pending invoice already exists.
     *
     * On a successful pay, also calls {@see noteReleasedHoldRemainderIfNeeded()} — a genuine
     * (matching) partial capture still means the un-captured remainder of the hold was auto-released
     * by NovaPay, which an admin reviewing the order needs to know about.
     *
     * Duplicate-capture guard (live evidence, orders 000000047/000000051/000000054): a pending
     * invoice only ever exists here because the admin ran "Capture Online", and registering that
     * invoice made Magento core record the capture transaction ALREADY — under core's own
     * `{parentTxnId}-capture` id, e.g. `9871113-auth-capture` (see
     * {@see \Magento\Sales\Model\Order\Payment\Transaction\Manager::generateTransactionId()}).
     * Unconditionally minting a second capture row here from the postback's own RRN
     * (`9871113-capture`) left every confirmed hold showing TWO capture transactions for one
     * captured payment — cosmetic (`total_paid` stayed correct) but a lying ledger. So
     * {@see findCaptureTransaction()} is consulted first, mirroring {@see applyHolded()}'s
     * `getAuthorizationTransaction()` guard: when a capture transaction already exists for this
     * payment/order, the pending invoice is simply paid and no second transaction is added. The
     * visually identical blocks in {@see applyPaid()} and {@see applyHoldConfirmed()} are
     * deliberately NOT guarded this way — they run only when the order has no invoice at all, i.e.
     * they are the ones creating the first (and only) capture row.
     *
     * Authorization-closing divergence (live evidence, orders 000000046/000000047/000000054): core
     * closes an authorization only when a capture consumes the FULL authorized amount, because in
     * the general case more of the same authorization could still be captured later. That is never
     * true for NovaPay — see {@see \NovaPay\Payment\Gateway\Command\CaptureCommand}'s class
     * docblock ("Only one complete-hold call is ever valid per hold ... NovaPay auto-refunds the
     * uncaptured difference on any call, full or partial") and its `assertNotAlreadyCaptured()`
     * guard. After a PARTIAL capture core therefore left the authorization `is_closed = 0`, and
     * Magento kept offering a Void button on the invoice of a fully settled order — a button whose
     * only possible outcome is a 404. This method therefore closes the authorization
     * UNCONDITIONALLY once the invoice is paid (idempotent on a full capture, where core already
     * closed it — one rule is simpler than two) through the shared {@see closeAuthorization()}
     * helper, which guards for `getAuthorizationTransaction()` returning `null`/`false` the same way
     * {@see \NovaPay\Payment\Gateway\Command\CaptureCommand::assertHoldNotExpired()} does and
     * persists the authorization inside the SAME {@see DbTransaction} as the invoice and the order.
     * The same helper runs on the offline-capture fallbacks of {@see applyPaid()} and
     * {@see applyHoldConfirmed()}, which pay an invoice for a hold confirmed on NovaPay's side.
     *
     * Partial capture remainder (D2): after `pay()`, {@see cancelReleasedHoldRemainder()} cancels
     * the uninvoiced quantity natively, in the same {@see DbTransaction}, so `canInvoice()` turns
     * false and the admin can no longer invoice money NovaPay has already released.
     *
     * @param Order $order
     * @param ParsedPostback $parsed
     * @param string $targetStatus the status label {@see apply()} sets right after this side effect
     * @return bool true if a pending invoice was found (finalized, or blocked by a mismatch — either
     *     way the caller should return early)
     */
    private function finalizePendingInvoice(Order $order, ParsedPostback $parsed, string $targetStatus): bool
    {
        $pendingInvoice = $this->findOpenInvoice($order);
        if ($pendingInvoice === null) {
            return false;
        }

        $reportedAmount = $parsed->getAmount();
        if ($reportedAmount !== null) {
            $invoiceTotal = (float)$pendingInvoice->getBaseGrandTotal();
            if (abs($reportedAmount - $invoiceTotal) > self::RECONCILE_TOLERANCE) {
                $this->logAndCommentPaidAmountMismatch($order, $parsed, $reportedAmount, $invoiceTotal, $targetStatus);

                return true;
            }
        }

        /** @var Payment $payment */
        $payment = $order->getPayment();
        $existingCapture = $this->findCaptureTransaction($order, $payment);

        if ($existingCapture !== null) {
            $this->logInfo(
                $order,
                'NovaPay capture confirmation received for an order whose capture transaction was '
                . 'already recorded by Magento when the admin invoice was registered; paying the '
                . 'pending invoice without adding a second capture transaction.',
                [
                    'event' => 'sideeffect.capture_already_recorded',
                    'order_increment_id' => $order->getIncrementId(),
                    'txn_id' => $existingCapture->getTxnId(),
                ]
            );
        } else {
            $payment->setTransactionId($this->buildTransactionId($parsed, 'capture'));
            $payment->setIsTransactionClosed(1);
            $payment->addTransaction(Transaction::TYPE_CAPTURE, $pendingInvoice, true);
        }

        $pendingInvoice->pay();
        $this->cancelReleasedHoldRemainder($order, $pendingInvoice);
        $this->noteReleasedHoldRemainderIfNeeded($order, $pendingInvoice, $targetStatus);

        $dbTransaction = $this->dbTransactionFactory->create()
            ->addObject($pendingInvoice)
            ->addObject($order);
        $this->closeAuthorization($order, $payment, $dbTransaction);
        $dbTransaction->save();

        return true;
    }

    /**
     * Closes the order's NovaPay authorization transaction (`is_closed = 1`) and adds it to
     * `$dbTransaction`, so it is persisted in the SAME {@see DbTransaction} as the invoice and the
     * order — an unsaved `setIsClosed()` is a silent no-op. Called by every path in this class that
     * pays an invoice for a captured hold: {@see finalizePendingInvoice()} and the offline-capture
     * fallbacks of {@see applyPaid()} and {@see applyHoldConfirmed()}.
     *
     * No-op (and no log line) when the payment has no authorization — `getAuthorizationTransaction()`
     * returns `null`/`false` for a direct-mode order — or when it is already closed (core closes it
     * itself on a full online capture; `Transaction::close()` would even throw on a closed failsafe
     * transaction, hence the explicit `is_closed` check rather than calling `close()`).
     *
     * @param Order $order
     * @param Payment $payment
     * @param DbTransaction $dbTransaction
     * @return void
     */
    private function closeAuthorization(Order $order, Payment $payment, DbTransaction $dbTransaction): void
    {
        $authorization = $payment->getAuthorizationTransaction();
        if (!$authorization instanceof Transaction || (int)$authorization->getIsClosed() === 1) {
            return;
        }

        $authorization->setIsClosed(1);
        $dbTransaction->addObject($authorization);
        $this->logInfo(
            $order,
            'Closing the NovaPay authorization transaction: a complete-hold call is only ever '
            . 'valid once per hold, so no further capture is possible after this one.',
            [
                'event' => 'sideeffect.authorization_closed',
                'order_increment_id' => $order->getIncrementId(),
                'txn_id' => $authorization->getTxnId(),
            ]
        );
    }

    /**
     * The capture transaction already recorded against this order's payment, if any — used by
     * {@see finalizePendingInvoice()} to avoid adding a second capture row for a single captured
     * payment. Deliberately a repository/search-criteria lookup rather than a
     * `$payment->getTransaction()` call: the id core generated at invoice time
     * (`{parentTxnId}-capture`) is NOT the id this class would build from the postback
     * ({@see buildTransactionId()}), so only a type-based lookup finds it.
     *
     * `getByTransactionType()` — which core's own
     * {@see \Magento\Sales\Model\Order\Payment\Transaction\Manager} uses for exactly this
     * kind of lookup — is not declared on {@see TransactionRepositoryInterface}, so the equivalent
     * filtered {@see TransactionRepositoryInterface::getList()} call is used instead.
     *
     * @param Order $order
     * @param OrderPaymentInterface $payment
     * @return TransactionInterface|null
     */
    private function findCaptureTransaction(Order $order, OrderPaymentInterface $payment): ?TransactionInterface
    {
        $paymentId = $payment->getEntityId();
        if (!$paymentId) {
            return null;
        }

        $searchCriteria = $this->searchCriteriaBuilder
            ->addFilter(TransactionInterface::PAYMENT_ID, (int)$paymentId)
            ->addFilter(TransactionInterface::ORDER_ID, (int)$order->getId())
            ->addFilter(TransactionInterface::TXN_TYPE, Transaction::TYPE_CAPTURE)
            ->create();

        $items = $this->transactionRepository->getList($searchCriteria)->getItems();

        return $items === [] ? null : (current($items) ?: null);
    }

    /**
     * The part of the hold NovaPay released after a partial capture.
     *
     * The order's `base_grand_total` minus the just-paid invoice's `base_grand_total`; zero (or a
     * float-rounding residue) for a full capture.
     *
     * @param Order $order
     * @param Invoice $invoice the just-paid invoice
     * @return float
     */
    private function releasedHoldRemainder(Order $order, Invoice $invoice): float
    {
        return (float)$order->getBaseGrandTotal() - (float)$invoice->getBaseGrandTotal();
    }

    /**
     * Cancels the uninvoiced quantity of a partially captured hold, the native way (D2).
     *
     * NovaPay allows exactly one complete-hold per hold and releases the uncaptured remainder to the
     * customer (see {@see \NovaPay\Payment\Gateway\Command\CaptureCommand}). Left open, that
     * quantity kept `Order::canInvoice()` true, and the new-invoice form for it only offers an
     * offline capture, so an admin could record a payment that never happened.
     *
     * Mirrors {@see Order::registerCancellation()} for the uninvoiced items only, without calling
     * `Order::cancel()` (which would cancel the whole order and its payment):
     * - every item tree (parent plus children) with any quantity left to invoice is canceled via
     *   {@see OrderItem::cancel()}, which sets `qty_canceled` and dispatches
     *   `sales_order_item_cancel` (stock is returned by the inventory observers);
     * - the order's canceled totals use core's own formulas (ordered minus invoiced for subtotal,
     *   tax, shipping and discount; grand total minus paid for the total). The first invoice
     *   always carries the full shipping in core, so shipping_canceled is normally 0; if shipping
     *   was not invoiced the same formula cancels it.
     *
     * Runs right after `Invoice::pay()`, so `total_paid` already includes the captured amount. The
     * order is saved by the caller's {@see DbTransaction}, which also saves the items.
     *
     * Idempotent via {@see INFO_REMAINDER_CANCELED}; a second run would also find nothing left to
     * invoice, and every total is assigned, never added.
     *
     * @param Order $order
     * @param Invoice $invoice the just-paid invoice
     * @return void
     */
    private function cancelReleasedHoldRemainder(Order $order, Invoice $invoice): void
    {
        $payment = $order->getPayment();
        if ($payment instanceof Payment && $payment->getAdditionalInformation(self::INFO_REMAINDER_CANCELED)) {
            return;
        }

        if ($this->releasedHoldRemainder($order, $invoice) <= self::RECONCILE_TOLERANCE) {
            return;
        }

        $canceledQty = 0.0;
        foreach ($this->collectItemsWithUninvoicedQty($order) as $item) {
            $item->cancel();
            $canceledQty += (float)$item->getQtyCanceled();
        }

        $order->setSubtotalCanceled((float)$order->getSubtotal() - (float)$order->getSubtotalInvoiced());
        $order->setBaseSubtotalCanceled((float)$order->getBaseSubtotal() - (float)$order->getBaseSubtotalInvoiced());

        $order->setTaxCanceled((float)$order->getTaxAmount() - (float)$order->getTaxInvoiced());
        $order->setBaseTaxCanceled((float)$order->getBaseTaxAmount() - (float)$order->getBaseTaxInvoiced());

        $order->setShippingCanceled((float)$order->getShippingAmount() - (float)$order->getShippingInvoiced());
        $order->setBaseShippingCanceled(
            (float)$order->getBaseShippingAmount() - (float)$order->getBaseShippingInvoiced()
        );

        $order->setDiscountCanceled(
            abs((float)$order->getDiscountAmount()) - abs((float)$order->getDiscountInvoiced())
        );
        $order->setBaseDiscountCanceled(
            abs((float)$order->getBaseDiscountAmount()) - abs((float)$order->getBaseDiscountInvoiced())
        );

        $order->setTotalCanceled((float)$order->getGrandTotal() - (float)$order->getTotalPaid());
        $order->setBaseTotalCanceled((float)$order->getBaseGrandTotal() - (float)$order->getBaseTotalPaid());

        if ($payment instanceof Payment) {
            $payment->setAdditionalInformation(self::INFO_REMAINDER_CANCELED, '1');
        }

        $this->logInfo(
            $order,
            'NovaPay partial capture: canceled the uninvoiced quantity whose funds NovaPay released.',
            [
                'event' => 'sideeffect.hold_remainder_canceled',
                'order_increment_id' => $order->getIncrementId(),
                'canceled_qty' => $canceledQty,
                'base_total_canceled' => (float)$order->getBaseTotalCanceled(),
            ]
        );
    }

    /**
     * The order items to cancel for {@see cancelReleasedHoldRemainder()}: every item tree (a
     * top-level item and its children) in which at least one item still has quantity to invoice.
     * Whole trees are canceled because core decides per product type whether the parent or the
     * children carry the invoiced quantity and the stock to return; fully invoiced trees are left
     * untouched.
     *
     * @param Order $order
     * @return OrderItem[]
     */
    private function collectItemsWithUninvoicedQty(Order $order): array
    {
        $trees = [];
        foreach ($order->getAllItems() as $item) {
            $parent = $item->getParentItem();
            $rootKey = spl_object_id($parent ?: $item);
            $trees[$rootKey]['items'][] = $item;
            $trees[$rootKey]['open'] = ($trees[$rootKey]['open'] ?? false) || $item->getQtyToInvoice() > 0;
        }

        $items = [];
        foreach ($trees as $tree) {
            if ($tree['open']) {
                array_push($items, ...$tree['items']);
            }
        }

        return $items;
    }

    /**
     * When a just-paid invoice's own total is less than the order's full `base_grand_total`, NovaPay
     * auto-released the un-captured remainder of the hold (see
     * {@see \NovaPay\Payment\Gateway\Request\CompleteHoldBuilder}'s class docblock — "the difference
     * is auto-refunded to the payer by NovaPay"). Adds ONE admin-only order status history comment
     * noting this; {@see cancelReleasedHoldRemainder()} has already canceled the uninvoiced quantity.
     * The comment is rendered in the admin locale and stamped with the target status (see
     * {@see addAdminComment()}).
     *
     * Idempotent via {@see INFO_PARTIAL_CAPTURE_NOTICE_ADDED}: a repeat `paid`/`hold_confirmed`
     * event for the same order (e.g. a replayed-but-not-hash-identical postback, or the get-status
     * cron re-confirming the same state) must never add the comment a second time.
     *
     * @param Order $order
     * @param Invoice $invoice the just-paid invoice
     * @param string $targetStatus the status label the comment is stamped with
     * @return void
     */
    private function noteReleasedHoldRemainderIfNeeded(Order $order, Invoice $invoice, string $targetStatus): void
    {
        $payment = $order->getPayment();
        if ($payment !== null && $payment->getAdditionalInformation(self::INFO_PARTIAL_CAPTURE_NOTICE_ADDED)) {
            return;
        }

        $capturedAmount = (float)$invoice->getBaseGrandTotal();
        $remainder = $this->releasedHoldRemainder($order, $invoice);

        if ($remainder <= self::RECONCILE_TOLERANCE) {
            return;
        }

        $this->addAdminComment(
            $order,
            __(
                'NovaPay captured %1 UAH and released the remaining %2 UAH of the hold. The uninvoiced '
                . 'quantity was canceled, so the remainder cannot be invoiced.',
                number_format($capturedAmount, 2, '.', ''),
                number_format($remainder, 2, '.', '')
            ),
            $targetStatus
        );

        if ($payment !== null) {
            $payment->setAdditionalInformation(self::INFO_PARTIAL_CAPTURE_NOTICE_ADDED, '1');
        }
    }

    /**
     * Persists `operations[0].transaction_id` (see {@see INFO_CAPTURE_OPERATION_ID}) from a `paid`/
     * `hold_confirmed` event, when present, as the order's payment additional_information. A no-op
     * when the event carries no `operations[]` at all, or its first entry has no scalar/non-empty
     * `transaction_id` — the existing `novapay_payment_id` (set once at checkout) remains the only
     * known operation id in that case, which {@see \NovaPay\Payment\Gateway\Command\RefundCommand}
     * already falls back to.
     *
     * @param Order $order
     * @param ParsedPostback $parsed
     * @return void
     */
    private function persistCaptureOperationId(Order $order, ParsedPostback $parsed): void
    {
        $operations = $parsed->getOperations();
        $first = is_array($operations) ? ($operations[0] ?? null) : null;
        if (!is_array($first)) {
            return;
        }

        $transactionId = $first['transaction_id'] ?? null;
        if (!is_scalar($transactionId)) {
            return;
        }

        $transactionId = trim((string)$transactionId);
        if ($transactionId === '') {
            return;
        }

        $payment = $order->getPayment();
        if ($payment !== null) {
            $payment->setAdditionalInformation(self::INFO_CAPTURE_OPERATION_ID, $transactionId);
        }
    }

    /**
     * `hold_confirmed`: the hold was captured. Marks the pending invoice created by the admin
     * "confirm hold" action as paid (via {@see finalizePendingInvoice()}). If no such invoice exists
     * yet, three cases are distinguished
     * (a repeat `hold_confirmed` with any differing field must never create a second, zero-amount
     * invoice):
     *
     * 1. An open (pending) invoice exists → capture it (the normal, expected path).
     * 2. No open invoice, but the order already has one or more invoices → this `hold_confirmed`
     *    was already applied by an earlier, differently-hashed postback for the same event; treat as
     *    already-applied and no-op rather than fabricating a second, empty invoice.
     * 3. No invoice at all yet, and {@see Order::canInvoice()} is false → the order genuinely cannot
     *    be invoiced right now (e.g. already canceled by a race); log and leave the status label to
     *    be applied by the caller without touching invoices/transactions.
     * 4. No invoice at all, and `canInvoice()` is true → the fallback case (postback/cron sync
     *    arrived before the admin confirm-hold action ever ran): create a fresh offline-capture
     *    invoice, logged clearly since this should not happen in normal operation — but only if
     *    {@see guardAgainstPaidAmountMismatch()} does not find NovaPay's reported amount at odds
     *    with the order's full total (this fallback invoices the FULL `base_grand_total`, which
     *    must never happen when NovaPay reports having captured less). The open authorization
     *    recorded by {@see applyHolded()} is closed in the same DB transaction via
     *    {@see closeAuthorization()}, so the paid order no longer offers a Void button.
     *
     * @param Order $order
     * @param ParsedPostback $parsed
     * @param string $targetStatus the status label {@see apply()} sets right after this side effect
     * @return void
     */
    private function applyHoldConfirmed(Order $order, ParsedPostback $parsed, string $targetStatus): void
    {
        /** @var Payment $payment */
        $payment = $order->getPayment();

        $this->persistCaptureOperationId($order, $parsed);

        if ($this->finalizePendingInvoice($order, $parsed, $targetStatus)) {
            return;
        }

        if ($order->getInvoiceCollection()->getSize() > 0) {
            $this->logInfo(
                $order,
                'NovaPay "hold_confirmed" postback received for an order that already has a capture '
                . 'invoice and no pending one; treating as already applied.',
                [
                    'event' => 'sideeffect.hold_confirmed_already_applied',
                    'order_increment_id' => $order->getIncrementId(),
                ]
            );

            return;
        }

        if (!$order->canInvoice()) {
            $this->logger->warning(
                'NovaPay "hold_confirmed" postback received for an order that cannot be invoiced '
                . '(canInvoice() is false); applying the status label only.',
                [
                    'event' => 'sideeffect.hold_confirmed_cannot_invoice',
                    'order_increment_id' => $order->getIncrementId(),
                ]
            );

            return;
        }

        if ($this->guardAgainstPaidAmountMismatch($order, $parsed, $targetStatus)) {
            return;
        }

        $this->logger->warning(
            'NovaPay "hold_confirmed" postback received with no pending invoice on the order; '
            . 'creating one now. This should normally already exist from the admin confirm-hold action.',
            ['event' => 'sideeffect.hold_confirmed_missing_invoice', 'order_increment_id' => $order->getIncrementId()]
        );

        $payment->setTransactionId($this->buildTransactionId($parsed, 'capture'));
        $payment->setIsTransactionClosed(1);

        $invoice = $this->invoiceService->prepareInvoice($order);
        $invoice->setRequestedCaptureCase(Invoice::CAPTURE_OFFLINE);
        $invoice->register();
        $payment->addTransaction(Transaction::TYPE_CAPTURE, $invoice, true);

        $dbTransaction = $this->dbTransactionFactory->create()
            ->addObject($invoice)
            ->addObject($order);
        $this->closeAuthorization($order, $payment, $dbTransaction);
        $dbTransaction->save();
    }

    /**
     * `voided` (session fully voided): three cases, distinguished by the order's invoice state.
     * `canCancel()` is evaluated against the order's true pre-transition state so an uninvoiced
     * hold's stock is actually restored instead of the check being permanently false.
     *
     * 1. **No invoice at all**: a hold canceled before ever being captured — released via
     *    {@see Order::cancel()}, whose stock-restore/item-cancel side effects still run.
     * 2. **An open (pending) invoice exists**: a capture (complete-hold) was sent to NovaPay
     *    ({@see \NovaPay\Payment\Gateway\Command\CaptureCommand} leaves the invoice
     *    `Invoice::STATE_OPEN` until `hold_confirmed` arrives), but NovaPay voided the session
     *    instead of ever confirming it. Canceled locally via {@see Invoice::cancel()} (a pure local
     *    ledger adjustment — never calls the gateway) and persisted via
     *    {@see InvoiceRepositoryInterface::save()}; no NovaPay call is made — this postback *is*
     *    NovaPay's own confirmation that the void already happened at their end.
     * 3. **Only paid/closed invoices, no open one** — distinguishes two situations:
     *    a. Magento's own ledger (`Order::getBaseTotalRefunded()`) already shows the order fully
     *       refunded — the normal case when an admin issued the refund via this module's native
     *       online credit memo ({@see \NovaPay\Payment\Gateway\Command\RefundCommand}), which adds
     *       the credit memo's amount into `base_total_refunded` synchronously before the gateway
     *       `refund` command even runs. Nothing to reconcile; log INFO only. Magento's own
     *       order-state auto-correction ({@see \Magento\Sales\Model\ResourceModel\Order\Handler\State::check()})
     *       will typically have already flipped the order to `closed` at credit-memo-submission
     *       time, before this postback arrives — this method's own state/label write (applied by
     *       the caller right after this method returns) is idempotent with whatever it already set.
     *    b. Magento's ledger does NOT (yet) show the order fully refunded — either no credit memo
     *       was ever created locally (the void was initiated at NovaPay directly) or a prior
     *       partial credit memo did not add up to the full amount. A genuine local/NovaPay mismatch
     *       a human must reconcile — logged at WARNING.
     *
     * @param Order $order
     * @return void
     */
    private function applyVoided(Order $order): void
    {
        if ($order->getInvoiceCollection()->getSize() === 0) {
            if ($order->canCancel()) {
                $order->cancel();
            }

            return;
        }

        $pendingInvoice = $this->findOpenInvoice($order);
        if ($pendingInvoice !== null) {
            $this->logger->warning(
                'NovaPay "voided" postback received while this order still has a pending (open) '
                . 'invoice: a capture (complete-hold) was sent to NovaPay, but NovaPay voided the '
                . 'session instead of ever confirming it (no "hold_confirmed" arrived). Canceling '
                . 'the stale pending invoice locally; no NovaPay call is made — this postback is '
                . 'itself the confirmation that the void already happened at NovaPay.',
                [
                    'event' => 'sideeffect.voided_pending_invoice_canceled',
                    'order_increment_id' => $order->getIncrementId(),
                    'invoice_id' => $pendingInvoice->getEntityId(),
                ]
            );

            $pendingInvoice->cancel();
            $this->invoiceRepository->save($pendingInvoice);

            return;
        }

        $baseTotalPaid = (float)$order->getBaseTotalPaid();
        $baseTotalRefunded = (float)$order->getBaseTotalRefunded();

        if ($baseTotalRefunded > 0.0 && abs($baseTotalPaid - $baseTotalRefunded) <= self::RECONCILE_TOLERANCE) {
            $this->logInfo(
                $order,
                'NovaPay "voided" postback received for an already-invoiced order: Magento\'s '
                . 'ledger already shows this order fully refunded (online or offline credit memo); '
                . 'nothing to reconcile.',
                [
                    'event' => 'sideeffect.voided_ledger_already_reconciled',
                    'order_increment_id' => $order->getIncrementId(),
                ]
            );

            return;
        }

        $this->logger->warning(
            'NovaPay "voided" postback received for an already-invoiced order, but Magento\'s own '
            . 'ledger does not show it fully refunded — this void may have been initiated at '
            . 'NovaPay directly rather than via an admin-issued online credit memo in this store. '
            . 'Reconcile manually. Applying the closed state/label only.',
            [
                'event' => 'sideeffect.voided_ledger_mismatch',
                'order_increment_id' => $order->getIncrementId(),
                'base_total_paid' => $baseTotalPaid,
                'base_total_refunded' => $baseTotalRefunded,
            ]
        );
    }

    /**
     * Cancel a failed order whose pending payment lifetime has passed (only via
     * {@see StatusMapper::cancelStaleFailedOrder()}, from the get-status cron): native
     * {@see Order::cancel()} (stock released), then `$label` on state `canceled`, an admin-only
     * history comment and one save. `novapay_status` is left as is (`failed`): no NovaPay event
     * happened. Returns false, after a warning, when the order can no longer be canceled.
     *
     * @param Order $order
     * @param string $label
     * @return bool
     */
    public function cancelStaleFailed(Order $order, string $label): bool
    {
        if (!$order->canCancel()) {
            $this->logger->warning(
                'NovaPay failed order is past its pending payment lifetime but can no longer be canceled.',
                ['event' => 'sideeffect.cancel_not_possible', 'order_increment_id' => $order->getIncrementId()]
            );

            return false;
        }

        $order->cancel();
        $order->setState(Order::STATE_CANCELED);
        $order->setStatus($label);
        $this->addAdminComment(
            $order,
            __(
                'NovaPay: the payment failed and was not retried within the pending payment order lifetime; '
                . 'the order was canceled and its stock released. If NovaPay later reports a payment '
                . 'for this session, reconcile it manually.'
            ),
            $label
        );
        $this->orderRepository->save($order);

        return true;
    }

    /**
     * `expired`: cancel the order (stock restored per Magento defaults). An order that is already
     * canceled (admin, core cleanup, or {@see cancelStaleFailed()}) is only relabeled (INFO, no second
     * cancel). Otherwise, if it can no longer be canceled (e.g. a race where it was already invoiced),
     * the anomaly is logged and only the status label (applied by the caller) changes. `canCancel()`
     * is evaluated against the order's true pre-transition state. `failed` never reaches here: it
     * does not cancel (see {@see apply()}).
     *
     * @param Order $order
     * @param ParsedPostback $parsed
     * @return void
     */
    private function applyExpired(Order $order, ParsedPostback $parsed): void
    {
        if ($order->getState() === Order::STATE_CANCELED) {
            $this->logInfo(
                $order,
                'NovaPay "expired" received for an order that is already canceled; applying the status label only.',
                ['event' => 'sideeffect.expired_already_canceled', 'order_increment_id' => $order->getIncrementId()]
            );

            return;
        }

        if ($order->canCancel()) {
            $order->cancel();

            return;
        }

        $this->logger->warning(
            'NovaPay "' . $parsed->getStatus() . '" postback received for an order that can no '
            . 'longer be canceled; applying the status label only.',
            ['event' => 'sideeffect.cancel_not_possible', 'order_increment_id' => $order->getIncrementId()]
        );
    }

    /**
     * Persist the per-payment fields every postback carries, regardless of status. Never mutates
     * card data beyond what NovaPay already sent masked. Also appends `$payloadHash` to the bounded
     * replay-detection history — the single write path for it, mirrored by the read-only
     * {@see hasProcessedHash()}.
     *
     * `novapay_last_event` always records the raw event status (audit-only). `novapay_status` — the
     * session-level status {@see StatusMapper}'s progression guard reads — is only updated when
     * `$isSessionStatus` is true: {@see apply()} (a genuine session-status transition) passes true;
     * {@see applyPartialVoid()} (a partial-refund event, NOT a session transition) passes false, so
     * it can never make an order look more "voided" than it actually is.
     *
     * @param Order $order
     * @param ParsedPostback $parsed
     * @param string $payloadHash
     * @param bool $isSessionStatus whether `$parsed`'s status is an actual session-level transition
     * @return void
     */
    private function persistPaymentInfo(
        Order $order,
        ParsedPostback $parsed,
        string $payloadHash,
        bool $isSessionStatus = true
    ): void {
        $payment = $order->getPayment();

        $payment->setAdditionalInformation(self::INFO_LAST_EVENT, $parsed->getStatus());
        if ($isSessionStatus) {
            $payment->setAdditionalInformation(self::INFO_STATUS, $parsed->getStatus());
        }
        $this->setIfPresent($payment, self::INFO_PAYTYPE, $parsed->getPaytype());
        $this->setIfPresent($payment, self::INFO_RRN, $parsed->getRrn());
        $this->setIfPresent($payment, self::INFO_APPROVAL, $parsed->getApproval());
        $this->setIfPresent($payment, self::INFO_CARD_PAN, $parsed->getMaskedPan());
        $this->setIfPresent($payment, self::INFO_CARD_BANK, $parsed->getCardBank());
        $this->setIfPresent($payment, self::INFO_CARD_COUNTRY, $parsed->getCardCountry());
        $this->setIfPresent($payment, self::INFO_CARD_TYPE, $parsed->getCardType());
        $payment->setAdditionalInformation(self::INFO_LAST_AT, $this->dateTime->gmtDate());

        $this->appendPayloadHash($order, $payloadHash);
    }

    /**
     * Append `$payloadHash` to the payment's `novapay_postback_hashes` list, keeping the newest
     * {@see MAX_STORED_HASHES} unique entries. Does not save.
     *
     * @param Order $order
     * @param string $payloadHash
     * @return void
     */
    private function appendPayloadHash(Order $order, string $payloadHash): void
    {
        $payment = $order->getPayment();
        $hashes = $this->decodeJsonArray($payment->getAdditionalInformation(self::INFO_HASHES));
        $hashes[] = $payloadHash;
        $hashes = array_slice(array_values(array_unique($hashes)), -self::MAX_STORED_HASHES);
        $payment->setAdditionalInformation(self::INFO_HASHES, $this->json->serialize($hashes));
    }

    /**
     * Set `$key` on the payment's additional_information only if `$value` is present. Accepts the
     * loosely-typed {@see OrderPaymentInterface}|null that `Order::getPayment()` actually returns
     * (every call site here reads it straight off an already-loaded {@see Order}) rather than the
     * concrete {@see Payment} — a real order always has a payment record by the time any of these
     * postback-driven code paths run, but the null case is guarded rather than assumed away.
     *
     * @param OrderPaymentInterface|null $payment
     * @param string $key
     * @param string|null $value
     * @return void
     */
    private function setIfPresent(?OrderPaymentInterface $payment, string $key, ?string $value): void
    {
        if ($payment !== null && $value !== null) {
            $payment->setAdditionalInformation($key, $value);
        }
    }

    /**
     * Re-key a previously decoded `novapay_refunded_operations` list by `id` — the shape
     * {@see applyPartialVoid()} needs to replace-by-id instead of blindly appending.
     *
     * @param array $operations each `{id: string, refund_amount: float, recorded_at: string}`
     * @return array keyed by `id`, each value the same shape as the input
     */
    private function indexRefundedOperationsById(array $operations): array
    {
        $indexed = [];
        foreach ($operations as $operation) {
            if (is_array($operation) && isset($operation['id'])) {
                $indexed[(string)$operation['id']] = $operation;
            }
        }

        return $indexed;
    }

    /**
     * Decode a previously JSON-serialized additional_information value (`novapay_refunded_operations`
     * or `novapay_postback_hashes`) that is expected to be a plain array. Never throws — a corrupt or
     * absent value is treated as an empty history rather than blocking the current postback.
     *
     * @param mixed $stored
     * @return array
     */
    private function decodeJsonArray(mixed $stored): array
    {
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
     * The first {@see Invoice::STATE_OPEN} invoice on the order, or null.
     *
     * @param Order $order
     * @return Invoice|null
     */
    private function findOpenInvoice(Order $order): ?Invoice
    {
        foreach ($order->getInvoiceCollection() as $invoice) {
            if ((int)$invoice->getState() === Invoice::STATE_OPEN) {
                return $invoice;
            }
        }

        return null;
    }

    /**
     * Deterministic, unique-per-event transaction id: `{RRN or session_id}-{suffix}`. Falls back to
     * the session id when no RRN is present (e.g. non-card paytypes), and the `-auth`/`-capture`
     * suffix keeps the authorization and capture rows distinct even when NovaPay reuses the same
     * RRN across both events of the same underlying card transaction.
     *
     * @param ParsedPostback $parsed
     * @param string $suffix "auth"|"capture"
     * @return string
     */
    private function buildTransactionId(ParsedPostback $parsed, string $suffix): string
    {
        $base = $parsed->getRrn() ?? $parsed->getSessionId();

        return $base . '-' . $suffix;
    }
}
