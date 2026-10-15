<?php
/**
 * Copyright © 2026 NovaPay. All rights reserved.
 * Licensed under the MIT License. See LICENSE for details.
 */
declare(strict_types=1);

namespace NovaPay\Payment\Model\HoldConfirm;

/**
 * Formats invoiced order lines for admin messages: "2 x Tee, 1 x Bag".
 */
class InvoiceLinesFormatter
{
    /**
     * Format the lines.
     *
     * @param array $lines [['name' => string, 'qty' => float], ...], see CaptureQtyResolver
     * @return string
     */
    public function format(array $lines): string
    {
        return implode(', ', array_map(
            static fn (array $line) => sprintf('%s x %s', (float)$line['qty'], $line['name']),
            $lines
        ));
    }
}
