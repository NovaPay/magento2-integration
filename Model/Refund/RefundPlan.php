<?php
/**
 * Copyright © 2026 NovaPay. All rights reserved.
 * Licensed under the MIT License. See LICENSE for details.
 */
declare(strict_types=1);

namespace NovaPay\Payment\Model\Refund;

/**
 * A validated NovaPay refund form submission, mapped onto one native credit memo
 * (see {@see RefundCalculator::buildPlan()}).
 *
 * - quantities: lines whose amount equals quantity x paid unit amount, refunded as credit memo item
 *   quantities (they can return to stock);
 * - adjustment lines: lines refunded by an amount that is not quantity x unit amount (or shipping);
 *   their sum is the credit memo's "Adjustment Refund", the line gets quantity 0.
 *
 * An empty plan means nothing was entered: the full refund (spec 8.2.5).
 */
class RefundPlan
{
    /**
     * @param array<int,float> $qtys order item id => quantity
     * @param array<string,float> $adjustmentLines order item id (string) or {@see RefundLedger::SHIPPING} => amount
     * @param array<string,float> $lineAmounts every refunded line => amount entered (quantity and adjustment lines)
     */
    public function __construct(
        private readonly array $qtys = [],
        private readonly array $adjustmentLines = [],
        private readonly array $lineAmounts = []
    ) {
    }

    /**
     * Quantities of the lines refunded by quantity.
     *
     * @return array<int,float>
     */
    public function getQtys(): array
    {
        return $this->qtys;
    }

    /**
     * Amounts of the lines refunded as "Adjustment Refund", order currency.
     *
     * @return array<string,float>
     */
    public function getAdjustmentLines(): array
    {
        return $this->adjustmentLines;
    }

    /**
     * Amount entered per refunded line, order currency.
     *
     * @return array<string,float>
     */
    public function getLineAmounts(): array
    {
        return $this->lineAmounts;
    }

    /**
     * Total of the entered amounts.
     *
     * @return float
     */
    public function getTotal(): float
    {
        return round(array_sum($this->lineAmounts), 2);
    }

    /**
     * Whether nothing was entered (full refund).
     *
     * @return bool
     */
    public function isEmpty(): bool
    {
        return $this->qtys === [] && $this->adjustmentLines === [];
    }
}
