<?php
/**
 * Copyright © 2026 NovaPay. All rights reserved.
 * Licensed under the MIT License. See LICENSE for details.
 */
declare(strict_types=1);

namespace NovaPay\Payment\Model\HoldConfirm;

use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\OrderFactory;
use Magento\Sales\Model\ResourceModel\Order as OrderResource;
use Magento\Sales\Model\Service\InvoiceService;

/**
 * Maps a hold confirmation amount to the item quantities of a standard Magento invoice.
 *
 * Magento invoices by quantity, and the postback pipeline expects the paid invoice total to equal
 * the amount NovaPay captured (a mismatch blocks the invoice from being finalized), after which it
 * cancels the uninvoiced quantity (D2). So a partial confirmation must be an invoice of whole
 * units whose total is exactly the confirmed amount:
 *
 * - the full hold amount invoices every item (an empty quantity list);
 * - a smaller amount is matched against every combination of whole units of the order lines (a
 *   bounded subset-sum search in cents, see buildSearch()). The first invoice always carries the full
 *   shipping (core behaviour), so shipping is part of every partial amount;
 * - the choice is made on core invoice totals (rounding, tax, discount), not on the estimate: every
 *   combination within the rounding slack of the amount is checked (see resolveSearch()). The result
 *   is an exact match when there is one (the caller confirms only then), else the largest total below
 *   the amount plus, as a second suggestion, the smallest one above it (below the full hold);
 * - orders too large for the search (see MAX_SEARCH_STATES) fall back to taking whole units of the
 *   lines in order as long as they fit, without a higher suggestion.
 *
 * Every total is computed by the core {@see InvoiceService::prepareInvoice()} on a separately loaded
 * copy of the order, never saved: prepareInvoice() adds the invoice to the order's invoice collection,
 * which would change the shipping of any later invoice built on the same order object.
 */
class CaptureQtyResolver
{
    /**
     * Amounts have 2 decimals: closer than this means equal (float noise only). The admin gets exactly
     * the amount confirmed, never a cent more.
     */
    public const AMOUNT_TOLERANCE = 0.001;

    /**
     * Core-total checks of the combinations nearest to the amount (the rounding window).
     */
    private const MAX_WINDOW_TRIALS = 20;

    /**
     * Core-total checks per direction outside the rounding window, until a lower / higher total is found.
     */
    private const MAX_ADJUSTMENTS = 10;

    /**
     * Search bounds. The search keeps two integer arrays with one entry per amount step (cents divided
     * by the greatest common divisor of the unit prices) up to the amount plus the rounding slack plus
     * the most expensive unit (the smallest total above the amount is at most one unit higher), and
     * visits every entry once per priced order line. 1,000,000 steps (10,000.00 at cent granularity)
     * is about 32 MB and well under a second; above either bound the resolver falls back to the
     * greedy rule (lines in order, as many units as fit; no higher suggestion).
     */
    private const MAX_SEARCH_STATES = 1000000;

    /**
     * See MAX_SEARCH_STATES: steps x priced lines.
     */
    private const MAX_SEARCH_WORK = 10000000;

    /**
     * @param OrderFactory $orderFactory
     * @param OrderResource $orderResource
     * @param InvoiceService $invoiceService
     * @param InvoiceLinesFormatter $linesFormatter
     */
    public function __construct(
        private readonly OrderFactory $orderFactory,
        private readonly OrderResource $orderResource,
        private readonly InvoiceService $invoiceService,
        private readonly InvoiceLinesFormatter $linesFormatter
    ) {
    }

    /**
     * The invoice quantities for a hold confirmation of `$amount` (base currency).
     *
     * @param Order $order
     * @param float $amount
     * @return array ['qtys' => [item id => qty], 'total' => float, 'full' => bool, 'lines' => [['name', 'qty']],
     *     'next_total' => ?float, 'next_lines' => [['name', 'qty']]]:
     *     `qtys` is empty for a full confirmation (invoice everything); `total` is the base grand
     *     total of the resulting invoice (the amount itself when a combination matches it, otherwise the
     *     largest core total below it); `lines` lists the invoiced order lines; `next_total` /
     *     `next_lines` is the smallest partial invoice total above the amount (below the full hold),
     *     only when `total` does not match the amount and one was found
     * @throws LocalizedException when not even one unit fits in the amount
     */
    public function resolve(Order $order, float $amount): array
    {
        $holdAmount = round((float)$order->getBaseGrandTotal(), 2);
        if (abs($holdAmount - $amount) <= self::AMOUNT_TOLERANCE) {
            return [
                'qtys' => [],
                'total' => $holdAmount,
                'full' => true,
                'lines' => $this->describe($order, []),
                'next_total' => null,
                'next_lines' => [],
            ];
        }

        $fullInvoice = $this->prepareTrialInvoice($order, []);
        $units = $this->getUnitAmounts($fullInvoice);
        $fixed = (float)$fullInvoice->getBaseGrandTotal() - array_sum(array_map(
            static fn (array $unit) => $unit['amount'] * $unit['qty'],
            $units
        ));
        $search = $this->buildSearch($units, $amount - $fixed);

        [$lower, $next] = $search === null
            ? [$this->resolveGreedy($order, $units, $amount, $fixed), null]
            : $this->resolveSearch($order, $search, $amount, $holdAmount);

        if ($lower === null) {
            if ($next !== null) {
                throw new LocalizedException(__(
                    'The amount %1 does not cover any whole item of the order together with the shipping. '
                    . 'The smallest amount that does is %2 (%3).',
                    number_format($amount, 2, '.', ''),
                    number_format($next['total'], 2, '.', ''),
                    $this->linesFormatter->format($this->describe($order, $next['qtys']))
                ));
            }
            throw new LocalizedException(__(
                'The amount %1 does not cover any whole item of the order together with the shipping. '
                . 'Enter a larger amount.',
                number_format($amount, 2, '.', '')
            ));
        }
        if (abs($lower['total'] - $amount) <= self::AMOUNT_TOLERANCE) {
            $next = null;
        }

        return [
            'qtys' => $lower['qtys'],
            'total' => $lower['total'],
            'full' => false,
            'lines' => $this->describe($order, $lower['qtys']),
            'next_total' => $next['total'] ?? null,
            'next_lines' => $next !== null ? $this->describe($order, $next['qtys']) : [],
        ];
    }

    /**
     * Pick the invoice by core totals.
     *
     * An exact match, else the largest total below the amount; and the smallest total above it (below
     * the full hold) as the higher suggestion.
     *
     * The estimate of a combination (sum of per-unit cents) can differ from its core invoice total by
     * the rounding of row discounts/taxes, so every combination within the rounding slack of the amount
     * is checked with the core totals (nearest first). Only when that window has no total below
     * (above) the amount does the scan continue downwards (upwards) to the next combinations.
     *
     * @param Order $order
     * @param array $search see buildSearch()
     * @param float $amount
     * @param float $holdAmount
     * @return array [?['qtys' => array, 'total' => float] lower or exact, ?[...] higher]
     */
    private function resolveSearch(Order $order, array $search, float $amount, float $holdAmount): array
    {
        $lower = null;
        $higher = null;
        $consider = function (array $qtys) use ($order, $amount, $holdAmount, &$lower, &$higher): bool {
            $total = round((float)$this->prepareTrialInvoice($order, $qtys)->getBaseGrandTotal(), 2);
            if (abs($total - $amount) <= self::AMOUNT_TOLERANCE) {
                $lower = ['qtys' => $qtys, 'total' => $total];

                return true;
            }
            if ($total < $amount) {
                if ($lower === null || $total > $lower['total']) {
                    $lower = ['qtys' => $qtys, 'total' => $total];
                }
            } elseif ($total < $holdAmount - self::AMOUNT_TOLERANCE
                && ($higher === null || $total < $higher['total'])
            ) {
                $higher = ['qtys' => $qtys, 'total' => $total];
            }

            return false;
        };

        $target = $search['target'];
        $slack = $search['slack'];
        $window = [];
        $windowEnd = min($search['limit'], $target + $slack);
        for ($sum = max(1, $target - $slack); $sum <= $windowEnd; $sum++) {
            if ($search['reach'][$sum] !== -1) {
                $window[] = $sum;
            }
        }
        // Nearest to the amount first; on equal distance the lower sum first (deterministic).
        usort($window, static fn (int $a, int $b) => [abs($a - $target), $a] <=> [abs($b - $target), $b]);
        foreach (array_slice($window, 0, self::MAX_WINDOW_TRIALS) as $sum) {
            if ($consider($this->rebuild($search, $sum))) {
                return [$lower, null];
            }
        }

        $trials = 0;
        for ($sum = min($search['limit'], $target - $slack - 1); $lower === null && $sum >= 1; $sum--) {
            if ($search['reach'][$sum] === -1) {
                continue;
            }
            if ($consider($this->rebuild($search, $sum)) || ++$trials >= self::MAX_ADJUSTMENTS) {
                break;
            }
        }

        if ($lower !== null && abs($lower['total'] - $amount) <= self::AMOUNT_TOLERANCE) {
            return [$lower, null];
        }

        $trials = 0;
        for ($sum = max(1, $target + $slack + 1); $higher === null && $sum <= $search['limit']; $sum++) {
            if ($search['reach'][$sum] === -1) {
                continue;
            }
            $consider($this->rebuild($search, $sum));
            if (++$trials >= self::MAX_ADJUSTMENTS) {
                break;
            }
        }

        return [$lower, $higher];
    }

    /**
     * Fallback for orders too large for the search.
     *
     * Whole units of the lines in order as long as they fit, then one priced unit less per step, last
     * line first, until the core total is not above the amount.
     *
     * @param Order $order
     * @param array $units see getUnitAmounts()
     * @param float $amount
     * @param float $fixed shipping and other totals outside the order lines
     * @return array|null ['qtys' => array, 'total' => float]
     */
    private function resolveGreedy(Order $order, array $units, float $amount, float $fixed): ?array
    {
        $qtys = [];
        $remaining = $amount - $fixed + self::AMOUNT_TOLERANCE;
        foreach ($units as $itemId => $unit) {
            $take = $unit['amount'] > 0
                ? min($unit['qty'], floor($remaining / $unit['amount'] + 1e-9))
                : $unit['qty'];
            if ($take > 0) {
                $qtys[$itemId] = (float)$take;
                $remaining -= $take * $unit['amount'];
            }
        }

        for ($attempt = 0; $attempt <= self::MAX_ADJUSTMENTS; $attempt++) {
            if (!$this->hasPricedUnit($qtys, $units)) {
                return null;
            }
            $total = round((float)$this->prepareTrialInvoice($order, $qtys)->getBaseGrandTotal(), 2);
            if ($total <= $amount + self::AMOUNT_TOLERANCE) {
                return ['qtys' => $qtys, 'total' => $total];
            }
            $qtys = $this->dropLastPricedUnit($qtys, $units);
        }

        return null;
    }

    /**
     * The search over whole units (bounded subset sum), or null when it exceeds the bounds.
     *
     * Unit amounts are integer cents divided by their greatest common divisor (`step`). `reach[s]` is
     * the index of the priced line whose units first reached the sum `s` steps when the lines are
     * added in order (-1: unreachable, -2: the empty sum), `take[s]` the units of that line in it.
     * Rebuilding a sum walks back line by line, so among several combinations with the same sum the
     * one using the earliest order lines is chosen (deterministic). Sums are searched up to `limit`:
     * the target plus the rounding slack plus one most expensive unit, never the full order (the full
     * hold is not a partial invoice).
     *
     * The rounding slack covers the difference between the estimate and the core totals: half a cent
     * per unit (per-unit cents are rounded) plus a cent per line (row rounding of tax/discount).
     *
     * @param array $units see getUnitAmounts()
     * @param float $itemAmount the amount minus shipping and other fixed totals
     * @return array|null ['lines' => [[id, steps, qty]], 'free' => [id => qty], 'order' => [id],
     *     'step' => int, 'target' => int, 'slack' => int, 'limit' => int, 'reach' => int[], 'take' => int[]]
     */
    private function buildSearch(array $units, float $itemAmount): ?array
    {
        $lines = [];
        $free = [];
        $step = 0;
        $total = 0;
        $maxUnit = 0;
        $unitCount = 0;
        foreach ($units as $itemId => $unit) {
            $cents = (int)round($unit['amount'] * 100);
            if ($cents <= 0) {
                $free[$itemId] = (float)$unit['qty'];
                continue;
            }
            $lines[] = [$itemId, $cents, (int)$unit['qty']];
            $step = $this->gcd($step, $cents);
            $total += $cents * (int)$unit['qty'];
            $maxUnit = max($maxUnit, $cents);
            $unitCount += (int)$unit['qty'];
        }
        if ($lines === []) {
            return null;
        }

        $target = (int)floor(($itemAmount * 100 + 0.1) / $step);
        $slack = (int)ceil((0.5 * $unitCount + count($lines)) / $step);
        $limit = min(intdiv($total, $step) - 1, max(0, $target) + $slack + intdiv($maxUnit, $step));
        if ($limit < 1) {
            return null;
        }
        if ($limit > self::MAX_SEARCH_STATES || $limit * count($lines) > self::MAX_SEARCH_WORK) {
            return null;
        }

        $reach = array_fill(0, $limit + 1, -1);
        $take = array_fill(0, $limit + 1, 0);
        $reach[0] = -2;
        foreach ($lines as $index => [, $cents, $qty]) {
            $steps = intdiv($cents, $step);
            $lines[$index][1] = $steps;
            for ($sum = $steps; $sum <= $limit; $sum++) {
                $from = $sum - $steps;
                if ($reach[$sum] !== -1 || $reach[$from] === -1) {
                    continue;
                }
                // Units of this line in the sum it extends: 0 when that sum was reached before this line.
                $used = $reach[$from] === $index ? $take[$from] : 0;
                if ($used >= $qty) {
                    continue;
                }
                $reach[$sum] = $index;
                $take[$sum] = $used + 1;
            }
        }

        return [
            'lines' => $lines,
            'free' => $free,
            'order' => array_keys($units),
            'step' => $step,
            'target' => $target,
            'slack' => $slack,
            'limit' => $limit,
            'reach' => $reach,
            'take' => $take,
        ];
    }

    /**
     * The quantities of a reachable sum, zero-priced lines included in full.
     *
     * @param array $search see buildSearch()
     * @param int $sum in steps
     * @return array item id => qty, in order
     */
    private function rebuild(array $search, int $sum): array
    {
        $taken = $search['free'];
        while ($sum > 0) {
            [$itemId, $steps] = $search['lines'][$search['reach'][$sum]];
            $taken[$itemId] = (float)$search['take'][$sum];
            $sum -= $search['take'][$sum] * $steps;
        }

        $qtys = [];
        foreach ($search['order'] as $itemId) {
            if (isset($taken[$itemId])) {
                $qtys[$itemId] = $taken[$itemId];
            }
        }

        return $qtys;
    }

    /**
     * Greatest common divisor.
     *
     * @param int $a
     * @param int $b
     * @return int
     */
    private function gcd(int $a, int $b): int
    {
        while ($b !== 0) {
            [$a, $b] = [$b, $a % $b];
        }

        return $a;
    }

    /**
     * Per top-level order line: the invoiceable quantity and the invoice amount of one unit.
     *
     * The unit amount is the row total with tax, minus discount, children included, per unit.
     *
     * @param Invoice $invoice a trial invoice of every item
     * @return array [top-level order item id => ['qty' => float, 'amount' => float]], in order
     */
    private function getUnitAmounts(Invoice $invoice): array
    {
        $lines = [];
        foreach ($invoice->getAllItems() as $invoiceItem) {
            $orderItem = $invoiceItem->getOrderItem();
            if ($orderItem === null) {
                continue;
            }
            $rootItem = $orderItem->getParentItem() ?: $orderItem;
            $rootId = (int)$rootItem->getId();
            $lines[$rootId] ??= ['qty' => floor((float)$rootItem->getQtyToInvoice()), 'total' => 0.0];
            $lines[$rootId]['total'] += (float)$invoiceItem->getBaseRowTotal()
                + (float)$invoiceItem->getBaseTaxAmount()
                + (float)$invoiceItem->getBaseDiscountTaxCompensationAmount()
                - abs((float)$invoiceItem->getBaseDiscountAmount());
        }

        $units = [];
        foreach ($lines as $rootId => $line) {
            if ($line['qty'] <= 0) {
                continue;
            }
            $units[$rootId] = ['qty' => $line['qty'], 'amount' => $line['total'] / $line['qty']];
        }

        return $units;
    }

    /**
     * Whether the quantities contain at least one unit with a price.
     *
     * @param array $qtys item id => qty
     * @param array $units see getUnitAmounts()
     * @return bool
     */
    private function hasPricedUnit(array $qtys, array $units): bool
    {
        foreach ($qtys as $itemId => $qty) {
            if ($qty > 0 && ($units[$itemId]['amount'] ?? 0) > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Remove one unit of the last priced line.
     *
     * @param array $qtys item id => qty
     * @param array $units see getUnitAmounts()
     * @return array item id => qty
     */
    private function dropLastPricedUnit(array $qtys, array $units): array
    {
        foreach (array_reverse(array_keys($qtys)) as $itemId) {
            if (($units[$itemId]['amount'] ?? 0) <= 0) {
                continue;
            }
            $qtys[$itemId] -= 1;
            if ($qtys[$itemId] <= 0) {
                unset($qtys[$itemId]);
            }
            break;
        }

        return $qtys;
    }

    /**
     * A trial invoice, built by the core service on a fresh copy of the order and never saved.
     *
     * @param Order $order
     * @param array $qtys item id => qty
     * @return Invoice
     */
    private function prepareTrialInvoice(Order $order, array $qtys): Invoice
    {
        $copy = $this->orderFactory->create();
        $this->orderResource->load($copy, (int)$order->getEntityId());

        /** @var Invoice $invoice */
        $invoice = $this->invoiceService->prepareInvoice($copy, $qtys);

        return $invoice;
    }

    /**
     * The invoiced top-level lines: name and quantity.
     *
     * @param Order $order
     * @param array $qtys item id => qty, empty for every invoiceable line
     * @return array [['name' => string, 'qty' => float], ...]
     */
    private function describe(Order $order, array $qtys): array
    {
        $lines = [];
        foreach ($order->getAllVisibleItems() as $item) {
            $qty = $qtys === [] ? (float)$item->getQtyToInvoice() : ($qtys[(int)$item->getId()] ?? 0.0);
            if ($qty > 0) {
                $lines[] = ['name' => (string)$item->getName(), 'qty' => $qty];
            }
        }

        return $lines;
    }
}
