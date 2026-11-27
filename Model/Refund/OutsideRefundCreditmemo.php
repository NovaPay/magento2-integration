<?php
/**
 * Copyright © 2026 NovaPay. All rights reserved.
 * Licensed under the MIT License. See LICENSE for details.
 */
declare(strict_types=1);

namespace NovaPay\Payment\Model\Refund;

use Magento\CatalogInventory\Api\StockConfigurationInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\CreditmemoManagementInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Creditmemo;
use Magento\Sales\Model\Order\CreditmemoFactory;

/**
 * Builds and registers the OFFLINE credit memo for a refund NovaPay made outside Magento (in the
 * NovaPay cabinet). No NovaPay call is made: the money was already returned.
 *
 * Only used by {@see \NovaPay\Payment\Model\OrderStatusApplier} (the postback / get-status pipeline,
 * the only place that changes NovaPay order state), which decides the amount and whether the refund
 * is full.
 *
 * - Full refund with nothing refunded in Magento yet: every remaining item and the remaining shipping
 *   of the paid invoice, as the native credit memo form prefills it (so the order closes), provided
 *   that credit memo totals exactly the NovaPay amount.
 * - Otherwise: an "Adjustment Refund" credit memo, every item quantity 0, shipping 0,
 *   `adjustment_positive` = the amount. Core accepts a credit memo without quantities when its total
 *   is positive; the zero-quantity items are not saved.
 *
 * Return to stock follows the store setting "Automatically Return Credit Memo Item to Stock"
 * (`cataloginventory/item_options/auto_return`), as for a native credit memo created without item
 * input; it only matters for the full credit memo, the adjustment one has no quantities. The credit
 * memo's transaction id is the NovaPay reference passed in.
 *
 * Core changes the order, its items and its payment in memory before its database transaction can
 * fail. On failure those in-memory values are restored, so the pipeline's own save afterwards does not
 * write half a refund, and the exception is rethrown.
 */
class OutsideRefundCreditmemo
{
    private const AMOUNT_TOLERANCE = 0.01;

    /**
     * @param CreditmemoFactory $creditmemoFactory
     * @param CreditmemoManagementInterface $creditmemoManagement
     * @param RefundCalculator $calculator
     * @param StockConfigurationInterface $stockConfiguration
     */
    public function __construct(
        private readonly CreditmemoFactory $creditmemoFactory,
        private readonly CreditmemoManagementInterface $creditmemoManagement,
        private readonly RefundCalculator $calculator,
        private readonly StockConfigurationInterface $stockConfiguration
    ) {
    }

    /**
     * Create and register the offline credit memo.
     *
     * @param Order $order the order instance of the pipeline (saved by core)
     * @param float $baseAmount refunded amount, base currency
     * @param string $reference NovaPay reference, stored as the credit memo transaction id
     * @param bool $fullRefund whether NovaPay refunded everything that was captured
     * @param callable|null $beforeRefund called with the built credit memo right before core refunds it:
     *     what it writes on the order is saved in core's transaction and undone on failure
     * @return Creditmemo
     * @throws LocalizedException when the amount is not positive, exceeds the refundable balance, or
     *     core refuses the credit memo
     */
    public function create(
        Order $order,
        float $baseAmount,
        string $reference,
        bool $fullRefund,
        ?callable $beforeRefund = null
    ): Creditmemo {
        $baseAmount = round($baseAmount, 2);
        $remaining = round((float)$order->getBaseTotalPaid() - (float)$order->getBaseTotalRefunded(), 2);
        if ($baseAmount <= self::AMOUNT_TOLERANCE || $baseAmount > $remaining + self::AMOUNT_TOLERANCE) {
            throw new LocalizedException(__(
                'The amount refunded by NovaPay (%1) does not fit the refundable balance of the order (%2).',
                number_format($baseAmount, 2, '.', ''),
                number_format($remaining, 2, '.', '')
            ));
        }

        $creditmemo = null;
        if ($fullRefund && (float)$order->getBaseTotalRefunded() <= self::AMOUNT_TOLERANCE) {
            $creditmemo = $this->buildFull($order);
            if ($creditmemo !== null
                && abs((float)$creditmemo->getBaseGrandTotal() - $baseAmount) > self::AMOUNT_TOLERANCE
            ) {
                $creditmemo = null;
            }
        }
        $creditmemo ??= $this->buildAdjustmentOnly($order, $baseAmount);

        $backToStock = (bool)$this->stockConfiguration->isAutoReturnEnabled();
        foreach ($creditmemo->getAllItems() as $item) {
            $item->setBackToStock($backToStock);
        }
        $creditmemo->setTransactionId($reference);
        $creditmemo->setOfflineRequested(true);
        if (!$creditmemo->isValidGrandTotal()) {
            throw new LocalizedException(__('The credit memo\'s total must be positive.'));
        }

        $snapshot = $this->snapshot($order);
        $order->setCustomerNoteNotify(0);
        try {
            if ($beforeRefund !== null) {
                $beforeRefund($creditmemo);
            }
            $this->creditmemoManagement->refund($creditmemo, true);
        } catch (\Throwable $e) {
            $this->restore($snapshot);
            throw $e instanceof LocalizedException ? $e : new LocalizedException(__($e->getMessage()), $e);
        }

        return $creditmemo;
    }

    /**
     * Credit memo of everything still refundable on the paid invoice (or the order), or null.
     *
     * @param Order $order
     * @return Creditmemo|null
     */
    private function buildFull(Order $order): ?Creditmemo
    {
        $invoice = $this->calculator->getRefundInvoice($order);
        if ($invoice !== null) {
            $invoice->setOrder($order);

            return $this->creditmemoFactory->createByInvoice($invoice, []);
        }

        return $order->canCreditmemo() ? $this->creditmemoFactory->createByOrder($order, []) : null;
    }

    /**
     * "Adjustment Refund" credit memo: all quantities 0, shipping 0.
     *
     * @param Order $order
     * @param float $baseAmount
     * @return Creditmemo
     */
    private function buildAdjustmentOnly(Order $order, float $baseAmount): Creditmemo
    {
        $qtys = [];
        foreach ($order->getAllItems() as $item) {
            $qtys[(int)$item->getId()] = 0;
        }

        return $this->creditmemoFactory->createByOrder($order, [
            'qtys' => $qtys,
            'shipping_amount' => '0',
            'adjustment_positive' => number_format($baseAmount, 2, '.', ''),
            'adjustment_negative' => '0',
        ]);
    }

    /**
     * The in-memory values core may change during the refund.
     *
     * @param Order $order
     * @return array<int, array{0: \Magento\Framework\DataObject, 1: array}>
     */
    private function snapshot(Order $order): array
    {
        $objects = [$order];
        if ($order->getPayment() !== null) {
            $objects[] = $order->getPayment();
        }
        foreach ($order->getAllItems() as $item) {
            $objects[] = $item;
        }

        $snapshot = [];
        foreach ($objects as $object) {
            $snapshot[] = [$object, $object->getData()];
        }

        return $snapshot;
    }

    /**
     * Put the snapshot values back.
     *
     * @param array $snapshot
     * @return void
     */
    private function restore(array $snapshot): void
    {
        foreach ($snapshot as [$object, $data]) {
            $object->setData($data);
        }
    }
}
