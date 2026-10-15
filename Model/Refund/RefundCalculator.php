<?php
/**
 * Copyright © 2026 NovaPay. All rights reserved.
 * Licensed under the MIT License. See LICENSE for details.
 */
declare(strict_types=1);

namespace NovaPay\Payment\Model\Refund;

use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\Order\Invoice\Item as InvoiceItem;
use Magento\Sales\Model\Order\Item as OrderItem;

/**
 * Rows, quantities and amounts of the NovaPay refund form.
 *
 * One row per refundable line of the paid invoice: the same lines the native credit memo form
 * offers a quantity input for (items that are not "dummy" in the credit memo sense, so a
 * configurable product is one row and a bundle with separately priced children is one row per
 * child). The amount per unit is what the customer actually paid for it on the invoice, tax
 * included, discount deducted.
 *
 * These amounts are for display and input validation only. The amount that is refunded is always
 * the total of the credit memo Magento builds from the chosen quantities.
 */
class RefundCalculator
{
    private const QTY_TOLERANCE = 0.0001;

    /**
     * The paid invoice a NovaPay refund is made against, or null when there is none left.
     *
     * NovaPay confirms one capture per order, so there is one paid invoice; the first paid invoice
     * that can still be refunded is used.
     *
     * @param Order $order
     * @return Invoice|null
     */
    public function getRefundInvoice(Order $order): ?Invoice
    {
        foreach ($order->getInvoiceCollection() as $invoice) {
            /** @var Invoice $invoice */
            if ((int)$invoice->getState() === Invoice::STATE_PAID && $invoice->canRefund()) {
                return $invoice;
            }
        }

        return null;
    }

    /**
     * The refund form rows for an invoice.
     *
     * @param Invoice $invoice
     * @return array<int, array{item_id: int, name: string, sku: string, qty_paid: float,
     *     qty_refunded: float, qty_refundable: float, unit_amount: float, is_qty_decimal: bool}>
     *     keyed by order item id
     */
    public function getRows(Invoice $invoice): array
    {
        $rows = [];
        foreach ($invoice->getAllItems() as $invoiceItem) {
            /** @var InvoiceItem $invoiceItem */
            $orderItem = $invoiceItem->getOrderItem();
            if (!$orderItem instanceof OrderItem || $orderItem->isDummy()) {
                continue;
            }

            $qtyPaid = (float)$invoiceItem->getQty();
            if ($qtyPaid <= 0.0) {
                continue;
            }

            $qtyRefunded = (float)$orderItem->getQtyRefunded();
            $qtyRefundable = max(0.0, min($qtyPaid - $qtyRefunded, (float)$orderItem->getQtyToRefund()));
            $paidAmount = (float)$invoiceItem->getRowTotalInclTax() - (float)$invoiceItem->getDiscountAmount();

            $itemId = (int)$orderItem->getId();
            $rows[$itemId] = [
                'item_id' => $itemId,
                'name' => (string)$orderItem->getName(),
                'sku' => (string)$orderItem->getSku(),
                'qty_paid' => $qtyPaid,
                'qty_refunded' => $qtyRefunded,
                'qty_refundable' => $qtyRefundable,
                'unit_amount' => max(0.0, $paidAmount / $qtyPaid),
                'is_qty_decimal' => (bool)$orderItem->getIsQtyDecimal(),
            ];
        }

        return $rows;
    }

    /**
     * Validate the quantities entered in the refund form.
     *
     * Empty or zero inputs are left out. An empty result means the merchant entered nothing, which
     * the spec defines as a full refund.
     *
     * @param array $rows as returned by {@see getRows()}
     * @param array $input order item id => entered quantity (string or number)
     * @return array<int,float> order item id => quantity to refund, only quantities above zero
     * @throws LocalizedException when a quantity is not a number, negative, fractional for a whole
     *     number item, above the refundable quantity, or for an unknown line
     */
    public function normalizeQtys(array $rows, array $input): array
    {
        $qtys = [];
        foreach ($input as $itemId => $rawQty) {
            $raw = is_scalar($rawQty) ? str_replace(',', '.', trim((string)$rawQty)) : '';
            if ($raw === '') {
                continue;
            }

            if (!isset($rows[(int)$itemId])) {
                throw new LocalizedException(__('The refund form contains an item that cannot be refunded.'));
            }

            $row = $rows[(int)$itemId];
            if (!is_numeric($raw) || (float)$raw < 0.0) {
                throw new LocalizedException(
                    __('Enter a valid quantity to refund for "%1".', $row['name'])
                );
            }

            $qty = (float)$raw;
            if (!$row['is_qty_decimal'] && abs($qty - round($qty)) > self::QTY_TOLERANCE) {
                throw new LocalizedException(
                    __('The quantity to refund for "%1" must be a whole number.', $row['name'])
                );
            }

            if ($qty > $row['qty_refundable'] + self::QTY_TOLERANCE) {
                throw new LocalizedException(
                    __(
                        'The quantity to refund for "%1" cannot be more than %2.',
                        $row['name'],
                        $this->formatQty($row['qty_refundable'])
                    )
                );
            }

            if ($qty > self::QTY_TOLERANCE) {
                $qtys[(int)$itemId] = $qty;
            }
        }

        return $qtys;
    }

    /**
     * Refund amount of one row for a quantity.
     *
     * @param array $row
     * @param float $qty
     * @return float
     */
    public function getLineAmount(array $row, float $qty): float
    {
        return round((float)$row['unit_amount'] * $qty, 2);
    }

    /**
     * Total refund amount of the chosen quantities.
     *
     * @param array $rows
     * @param array<int,float> $qtys
     * @return float
     */
    public function getTotal(array $rows, array $qtys): float
    {
        $total = 0.0;
        foreach ($qtys as $itemId => $qty) {
            if (isset($rows[$itemId])) {
                $total += $this->getLineAmount($rows[$itemId], (float)$qty);
            }
        }

        return round($total, 2);
    }

    /**
     * Amount a full refund returns: everything paid and not yet refunded, shipping included.
     *
     * @param Order $order
     * @return float
     */
    public function getFullRefundAmount(Order $order): float
    {
        return round(max(0.0, (float)$order->getTotalPaid() - (float)$order->getTotalRefunded()), 2);
    }

    /**
     * A quantity without trailing zeros (2 instead of 2.0000).
     *
     * @param float $qty
     * @return string
     */
    public function formatQty(float $qty): string
    {
        $formatted = rtrim(rtrim(number_format($qty, 4, '.', ''), '0'), '.');

        return $formatted === '' ? '0' : $formatted;
    }
}
