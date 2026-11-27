<?php
/**
 * Copyright © 2026 NovaPay. All rights reserved.
 * Licensed under the MIT License. See LICENSE for details.
 */
declare(strict_types=1);

namespace NovaPay\Payment\Model\Refund;

use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\CreditmemoManagementInterface;
use Magento\Sales\Api\Data\CreditmemoInterface;
use Magento\Sales\Controller\Adminhtml\Order\CreditmemoLoader;
use Magento\Sales\Model\Order\Creditmemo;
use Magento\Sales\Model\Order\Invoice;

/**
 * Creates the standard online credit memo for the NovaPay refund form.
 *
 * It repeats what the native "Refund" button of the credit memo form does
 * ({@see \Magento\Sales\Controller\Adminhtml\Order\Creditmemo\Save}): the core
 * {@see CreditmemoLoader} builds the credit memo from the paid invoice and the posted item data
 * (quantities, "Return to Stock" flags, shipping and adjustments), the parent quantities are
 * aligned with their children, and {@see CreditmemoManagementInterface::refund()} refunds it online.
 * That runs the module's refund gateway command (`POST /v1/void`) inside the core database
 * transaction, so a failed NovaPay call leaves no credit memo behind.
 *
 * Full refund (nothing entered): every remaining item plus the remaining shipping, exactly as the
 * native form prefills it. Partial refund: the lines refunded by quantity get their quantity, every
 * other line quantity 0, shipping 0; the lines refunded by an amount ({@see RefundPlan}) are summed
 * into "Adjustment Refund" (`adjustment_positive`, base currency). "Adjustment Fee" is always 0.
 *
 * The per-line attribution of that adjustment is handed to the refund gateway command on the credit
 * memo object ({@see ADJUSTMENT_LINES}, never persisted, not settable from a request): the command
 * accepts an adjustment refund only when it carries this attribution and the amounts add up. The
 * refunded amount per line is written to {@see RefundLedger} on the order's payment, saved by core
 * together with the credit memo (a failed NovaPay call rolls both back).
 */
class CreditmemoRefunder
{
    /**
     * Credit memo data key: line => base amount of the "Adjustment Refund" made by the refund form.
     */
    public const ADJUSTMENT_LINES = 'novapay_adjustment_lines';

    /**
     * @param CreditmemoLoader $creditmemoLoader
     * @param CreditmemoManagementInterface $creditmemoManagement
     * @param RefundLedger $ledger
     */
    public function __construct(
        private readonly CreditmemoLoader $creditmemoLoader,
        private readonly CreditmemoManagementInterface $creditmemoManagement,
        private readonly RefundLedger $ledger
    ) {
    }

    /**
     * Build and refund the credit memo online.
     *
     * @param Invoice $invoice the paid invoice to refund against
     * @param int[] $itemIds order item ids of all refund form rows
     * @param array<int,float> $qtys order item id => quantity; empty for a full refund
     * @param array<int,bool> $backToStock order item id => return the refunded quantity to stock
     * @param array<string,float> $adjustmentLines line => amount (order currency) refunded as an
     *     amount, see {@see RefundPlan::getAdjustmentLines()}
     * @param float|null $expectedTotal the total the admin confirmed (order currency); the credit memo
     *     total may differ from it only by rounding (one cent per line), and then the credit memo total
     *     is what is refunded
     * @return CreditmemoInterface
     * @throws LocalizedException when the credit memo cannot be built, its total does not match the
     *     confirmed total, or the refund fails
     */
    public function refund(
        Invoice $invoice,
        array $itemIds,
        array $qtys,
        array $backToStock,
        array $adjustmentLines = [],
        ?float $expectedTotal = null
    ): CreditmemoInterface {
        $isFullRefund = $qtys === [] && $adjustmentLines === [];
        $baseAdjustmentLines = $this->toBaseAmounts($invoice, $adjustmentLines);
        $baseAdjustment = round(array_sum($baseAdjustmentLines), 2);

        $items = [];
        foreach ($itemIds as $itemId) {
            $itemData = [];
            $qty = (float)($qtys[$itemId] ?? 0.0);
            if (!$isFullRefund) {
                $itemData['qty'] = $qty;
            }
            if (!empty($backToStock[$itemId]) && ($isFullRefund || $qty > 0.0)) {
                $itemData['back_to_stock'] = '1';
            }
            $items[(int)$itemId] = $itemData;
        }

        $data = [
            'items' => $items,
            'do_offline' => '0',
            'adjustment_positive' => $baseAdjustment > 0.0 ? number_format($baseAdjustment, 2, '.', '') : '0',
            'adjustment_negative' => '0',
        ];
        if (!$isFullRefund) {
            $data['shipping_amount'] = '0';
        }

        $this->creditmemoLoader->setCreditmemoId(null);
        $this->creditmemoLoader->setOrderId($invoice->getOrderId());
        $this->creditmemoLoader->setInvoiceId($invoice->getId());
        $this->creditmemoLoader->setCreditmemo($data);
        $creditmemo = $this->creditmemoLoader->load();
        if (!$creditmemo instanceof Creditmemo) {
            throw new LocalizedException(__('We can\'t create credit memo for the order.'));
        }

        $this->adjustParentQuantities($creditmemo);

        if (!$creditmemo->isValidGrandTotal()) {
            throw new LocalizedException(__('The credit memo\'s total must be positive.'));
        }
        if ($expectedTotal !== null) {
            $tolerance = 0.01 * (count($qtys) + count($adjustmentLines) + 1) + 0.001;
            if (abs((float)$creditmemo->getGrandTotal() - $expectedTotal) > $tolerance) {
                throw new LocalizedException(__(
                    'The credit memo total %1 does not match the entered refund total %2. Check the amounts '
                    . 'and try again.',
                    number_format((float)$creditmemo->getGrandTotal(), 2, '.', ''),
                    number_format($expectedTotal, 2, '.', '')
                ));
            }
        }

        if ($baseAdjustmentLines !== []) {
            $creditmemo->setData(self::ADJUSTMENT_LINES, $baseAdjustmentLines);
        }
        $this->recordLedger($creditmemo, $itemIds, $adjustmentLines);

        $creditmemo->setOfflineRequested(false);
        $creditmemo->getOrder()->setCustomerNoteNotify(0);

        return $this->creditmemoManagement->refund($creditmemo, false);
    }

    /**
     * Write the refunded amount per line to the payment's refund ledger.
     *
     * The quantity lines with the credit memo item amounts (paid incl. tax minus discount), shipping,
     * and the amount lines.
     *
     * @param Creditmemo $creditmemo
     * @param int[] $itemIds
     * @param array<string,float> $adjustmentLines
     * @return void
     */
    private function recordLedger(Creditmemo $creditmemo, array $itemIds, array $adjustmentLines): void
    {
        $payment = $creditmemo->getOrder()->getPayment();
        if ($payment === null) {
            return;
        }

        $amounts = [];
        $rowIds = array_map('intval', $itemIds);
        foreach ($creditmemo->getAllItems() as $item) {
            $orderItemId = (int)$item->getOrderItemId();
            if ((float)$item->getQty() <= 0.0 || !in_array($orderItemId, $rowIds, true)) {
                continue;
            }
            $amounts[(string)$orderItemId] = (float)$item->getRowTotalInclTax() - (float)$item->getDiscountAmount();
        }

        $shipping = (float)$creditmemo->getShippingInclTax();
        if ($shipping > 0.0) {
            $amounts[RefundLedger::SHIPPING] = $shipping;
        }
        foreach ($adjustmentLines as $line => $amount) {
            $amounts[(string)$line] = ($amounts[(string)$line] ?? 0.0) + (float)$amount;
        }

        $this->ledger->addLines($payment, $amounts);
    }

    /**
     * Order currency amounts converted to the base currency (NovaPay refunds base amounts).
     *
     * The rounding residual goes to the last line, so the lines add up exactly to the converted total
     * that becomes `adjustment_positive` (the refund gateway command compares the two).
     *
     * @param Invoice $invoice
     * @param array<string,float> $amounts
     * @return array<string,float>
     */
    private function toBaseAmounts(Invoice $invoice, array $amounts): array
    {
        $rate = $this->getRate($invoice);
        $amounts = array_filter($amounts, static fn ($amount): bool => (float)$amount > 0.0);
        if ($amounts === []) {
            return [];
        }

        $baseTotal = round(array_sum(array_map('floatval', $amounts)) / $rate, 2);
        $base = [];
        foreach ($amounts as $line => $amount) {
            $base[(string)$line] = round((float)$amount / $rate, 2);
        }
        $lastLine = array_key_last($base);
        $base[$lastLine] = round($base[$lastLine] + $baseTotal - array_sum($base), 2);

        return $base;
    }

    /**
     * Base-to-order currency rate of the invoice's order, 1 when unknown.
     *
     * @param Invoice $invoice
     * @return float
     */
    private function getRate(Invoice $invoice): float
    {
        $order = $invoice->getOrder();
        $rate = $order !== null ? (float)$order->getBaseToOrderRate() : 1.0;

        return $rate > 0.0 ? $rate : 1.0;
    }

    /**
     * Parent item quantity = sum of its children, as the native credit memo Save controller does.
     *
     * @param Creditmemo $creditmemo
     * @return void
     */
    private function adjustParentQuantities(Creditmemo $creditmemo): void
    {
        $items = $creditmemo->getAllItems();
        $parentQuantities = [];
        foreach ($items as $item) {
            $parentId = $item->getOrderItem()->getParentItemId();
            if ($parentId) {
                $parentQuantities[$parentId] = ($parentQuantities[$parentId] ?? 0) + $item->getQty();
            }
        }

        foreach ($parentQuantities as $parentId => $quantity) {
            foreach ($items as $item) {
                if ($item->getOrderItemId() == $parentId) {
                    $item->setQty($quantity);
                }
            }
        }
    }
}
