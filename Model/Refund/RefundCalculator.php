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
use Magento\Sales\Model\Order\Payment;

/**
 * Rows, quantities and amounts of the NovaPay refund form.
 *
 * One row per refundable line of the paid invoice: the same lines the native credit memo form
 * offers a quantity input for (items that are not "dummy" in the credit memo sense, so a
 * configurable product is one row and a bundle with separately priced children is one row per
 * child). The amount per unit is what the customer actually paid for it on the invoice, tax
 * included, discount deducted.
 *
 * Per line the admin enters a quantity and/or an amount ("Вартість до повернення"). A partial
 * refund is not tied to the product price (NovaPay, 2026-11): the only limits are the line's own
 * refundable amount (paid for the line minus already refunded for it) and, in total, the captured
 * amount minus what was already refunded. {@see buildPlan()} maps the input onto one native credit
 * memo: a line whose amount equals quantity x unit amount is a normal quantity refund, any other
 * amount (and shipping) becomes part of the credit memo's "Adjustment Refund" with quantity 0.
 */
class RefundCalculator
{
    private const QTY_TOLERANCE = 0.0001;
    private const AMOUNT_TOLERANCE = 0.005;

    /**
     * @param RefundLedger $ledger
     */
    public function __construct(
        private readonly RefundLedger $ledger
    ) {
    }

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
     * `paid_amount` is what the customer paid for the line, `refunded_amount` what was refunded for it
     * (the NovaPay refund ledger, or refunded quantity x unit amount for a credit memo made without the
     * form, whichever is higher), `amount_refundable` the difference.
     *
     * @param Invoice $invoice
     * @return array<int, array{item_id: int, name: string, sku: string, qty_paid: float,
     *     qty_refunded: float, qty_refundable: float, unit_amount: float, is_qty_decimal: bool,
     *     paid_amount: float, refunded_amount: float, amount_refundable: float}>
     *     keyed by order item id
     */
    public function getRows(Invoice $invoice): array
    {
        $ledgerLines = $this->ledger->getLines($this->getPayment($invoice));
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
            $unitAmount = max(0.0, $paidAmount / $qtyPaid);
            $refundedAmount = round(max(
                (float)($ledgerLines[(string)$itemId] ?? 0.0),
                $unitAmount * $qtyRefunded
            ), 2);
            $paidAmount = round(max(0.0, $paidAmount), 2);
            $rows[$itemId] = [
                'item_id' => $itemId,
                'name' => (string)$orderItem->getName(),
                'sku' => (string)$orderItem->getSku(),
                'qty_paid' => $qtyPaid,
                'qty_refunded' => $qtyRefunded,
                'qty_refundable' => $qtyRefundable,
                'unit_amount' => $unitAmount,
                'is_qty_decimal' => (bool)$orderItem->getIsQtyDecimal(),
                'paid_amount' => $paidAmount,
                'refunded_amount' => $refundedAmount,
                'amount_refundable' => round(max(0.0, $paidAmount - $refundedAmount), 2),
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
     * The shipping row of the refund form (amount input only), or null when no shipping was paid.
     *
     * Paid shipping = the invoice's shipping incl. tax minus the order's shipping discount;
     * refunded = the ledger's shipping entry or the order's refunded shipping incl. tax, whichever
     * is higher.
     *
     * @param Invoice $invoice
     * @return array{item_id: string, name: string, paid_amount: float, refunded_amount: float,
     *     amount_refundable: float}|null
     */
    public function getShippingRow(Invoice $invoice): ?array
    {
        $order = $invoice->getOrder();
        $discount = $order instanceof Order ? (float)$order->getShippingDiscountAmount() : 0.0;
        $paid = round(max(0.0, (float)$invoice->getShippingInclTax() - abs($discount)), 2);
        if ($paid <= 0.0) {
            return null;
        }

        $orderRefunded = $order instanceof Order
            ? (float)$order->getShippingRefunded() + (float)$order->getShippingTaxRefunded()
            : 0.0;
        $ledgerLines = $this->ledger->getLines($this->getPayment($invoice));
        $refunded = round(max((float)($ledgerLines[RefundLedger::SHIPPING] ?? 0.0), $orderRefunded), 2);

        return [
            'item_id' => RefundLedger::SHIPPING,
            'name' => (string)__('Shipping'),
            'paid_amount' => $paid,
            'refunded_amount' => $refunded,
            'amount_refundable' => round(max(0.0, $paid - $refunded), 2),
        ];
    }

    /**
     * Amount refunded through NovaPay without a line (a refund made in the NovaPay cabinet).
     *
     * @param Order $order
     * @return float
     */
    public function getUnattributedRefunded(Order $order): float
    {
        $payment = $order->getPayment();

        return $payment instanceof Payment ? $this->ledger->getUnattributed($payment) : 0.0;
    }

    /**
     * Validate the refund form input and map it onto one credit memo.
     *
     * Per line: an empty amount with a quantity means quantity x unit amount; an amount without a
     * quantity, or one that differs from quantity x unit amount, is refunded as an amount (the line
     * gets quantity 0, the amount goes to the "Adjustment Refund", no return to stock). Shipping is
     * always an amount. Nothing entered anywhere returns an empty plan (full refund).
     *
     * @param array $rows as returned by {@see getRows()}
     * @param array|null $shippingRow as returned by {@see getShippingRow()}
     * @param array $qtyInput order item id => entered quantity
     * @param array $amountInput order item id => entered amount
     * @param mixed $shippingInput entered shipping amount
     * @param float $maxTotal the most that can still be refunded (captured minus refunded)
     * @return RefundPlan
     * @throws LocalizedException on any invalid quantity or amount, or a total above `$maxTotal`
     */
    public function buildPlan(
        array $rows,
        ?array $shippingRow,
        array $qtyInput,
        array $amountInput,
        mixed $shippingInput,
        float $maxTotal
    ): RefundPlan {
        $qtys = $this->normalizeQtys($rows, $qtyInput);

        foreach ($amountInput as $itemId => $rawAmount) {
            if (!isset($rows[(int)$itemId]) && $this->parseAmount($rawAmount, '') !== null) {
                throw new LocalizedException(__('The refund form contains an item that cannot be refunded.'));
            }
        }

        $planQtys = [];
        $adjustmentLines = [];
        $lineAmounts = [];
        foreach ($rows as $itemId => $row) {
            $qty = (float)($qtys[$itemId] ?? 0.0);
            $qtyAmount = $this->getLineAmount($row, $qty);
            $amount = $this->parseAmount($amountInput[$itemId] ?? null, (string)$row['name']);
            if ($amount === null) {
                $amount = $qtyAmount;
            }

            if ($qty > 0.0 && abs($amount - $qtyAmount) < self::AMOUNT_TOLERANCE) {
                // Quantity refund (a free item keeps amount 0 and can still return to stock).
                $this->assertWithinLine($row, $amount);
                $planQtys[(int)$itemId] = $qty;
                $lineAmounts[(string)$itemId] = $qtyAmount;
                continue;
            }
            if ($qty <= 0.0 && $amount <= 0.0) {
                continue;
            }
            if ($amount <= 0.0) {
                throw new LocalizedException(__(
                    'Enter an amount above 0 to refund for "%1", or clear its quantity.',
                    $row['name']
                ));
            }
            $this->assertWithinLine($row, $amount);
            $lineAmounts[(string)$itemId] = $amount;
            $adjustmentLines[(string)$itemId] = $amount;
        }

        $shippingAmount = $shippingRow !== null ? $this->parseAmount($shippingInput, $shippingRow['name']) : null;
        if ($shippingAmount !== null && $shippingAmount > 0.0) {
            $this->assertWithinLine($shippingRow, $shippingAmount);
            $lineAmounts[RefundLedger::SHIPPING] = $shippingAmount;
            $adjustmentLines[RefundLedger::SHIPPING] = $shippingAmount;
        }

        $plan = new RefundPlan($planQtys, $adjustmentLines, $lineAmounts);
        if (!$plan->isEmpty() && $plan->getTotal() > round($maxTotal, 2) + self::AMOUNT_TOLERANCE) {
            throw new LocalizedException(__(
                'The total amount to refund cannot be more than %1.',
                $this->formatAmountValue($maxTotal)
            ));
        }

        return $plan;
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
     * An amount with two decimals, as used in validation messages.
     *
     * @param float $amount
     * @return string
     */
    public function formatAmountValue(float $amount): string
    {
        return number_format($amount, 2, '.', '');
    }

    /**
     * Parse an entered amount: null when empty, else the amount rounded to cents.
     *
     * @param mixed $rawAmount
     * @param string $lineName for the error message
     * @return float|null
     * @throws LocalizedException when the amount is not a number or negative
     */
    private function parseAmount(mixed $rawAmount, string $lineName): ?float
    {
        $raw = is_scalar($rawAmount) ? str_replace([',', ' '], ['.', ''], trim((string)$rawAmount)) : '';
        if ($raw === '') {
            return null;
        }
        if (!is_numeric($raw) || (float)$raw < 0.0) {
            throw new LocalizedException(__('Enter a valid amount to refund for "%1".', $lineName));
        }

        return round((float)$raw, 2);
    }

    /**
     * The amount must not exceed what is still refundable for the line.
     *
     * @param array $row
     * @param float $amount
     * @return void
     * @throws LocalizedException
     */
    private function assertWithinLine(array $row, float $amount): void
    {
        if ($amount > (float)$row['amount_refundable'] + self::AMOUNT_TOLERANCE) {
            throw new LocalizedException(__(
                'The amount to refund for "%1" cannot be more than %2.',
                $row['name'],
                $this->formatAmountValue((float)$row['amount_refundable'])
            ));
        }
    }

    /**
     * The payment of the invoice's order, or null.
     *
     * @param Invoice $invoice
     * @return Payment|null
     */
    private function getPayment(Invoice $invoice): ?Payment
    {
        $order = $invoice->getOrder();
        $payment = $order instanceof Order ? $order->getPayment() : null;

        return $payment instanceof Payment ? $payment : null;
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
