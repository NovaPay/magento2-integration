<?php
/**
 * Copyright © 2026 NovaPay. All rights reserved.
 * Licensed under the MIT License. See LICENSE for details.
 */
declare(strict_types=1);

namespace NovaPay\Payment\Model\HoldConfirm;

use Magento\Framework\Exception\LocalizedException;

/**
 * Server-side check of the amount entered in the NovaPay hold confirmation form (spec 7.3.3):
 * a number with at most 2 decimals, greater than zero and not above the hold amount. A smaller
 * amount (partial confirmation) is only accepted when a Recipient Identifier is configured.
 * The form's JavaScript applies the same rules before submit.
 */
class AmountValidator
{
    /**
     * Both amounts have 2 decimals: closer than this means equal (float noise only).
     */
    private const AMOUNT_TOLERANCE = 0.001;

    /**
     * The entered amount as a number rounded to 2 decimals.
     *
     * @param mixed $rawAmount the `amount` request parameter; "," is accepted as the decimal separator
     * @param float $holdAmount
     * @param bool $partialAllowed whether a Recipient Identifier is configured
     * @return float
     * @throws LocalizedException
     */
    public function validate(mixed $rawAmount, float $holdAmount, bool $partialAllowed): float
    {
        $normalized = is_scalar($rawAmount)
            ? str_replace([' ', "\u{00A0}", ','], ['', '', '.'], trim((string)$rawAmount))
            : '';
        if (!preg_match('/^\d+(\.\d{1,2})?$/', $normalized)) {
            throw new LocalizedException(__(
                'Enter the amount to confirm as a number with at most 2 decimals, for example 250.50.'
            ));
        }

        $amount = round((float)$normalized, 2);
        if ($amount <= 0) {
            throw new LocalizedException(__('The amount to confirm must be greater than zero.'));
        }

        // Both amounts have 2 decimals: anything above the hold amount, even by 0.01, is rejected.
        if ($amount - round($holdAmount, 2) > self::AMOUNT_TOLERANCE) {
            throw new LocalizedException(__(
                'The amount to confirm cannot exceed the hold amount of %1.',
                number_format($holdAmount, 2, '.', '')
            ));
        }

        if (!$partialAllowed && !$this->isFullAmount($amount, $holdAmount)) {
            throw new LocalizedException(__(
                'A partial hold confirmation requires the Recipient Identifier (ЄДРПОУ/ІПН) in the NovaPay '
                . 'payment settings. Confirm the full hold amount or configure the identifier.'
            ));
        }

        return $amount;
    }

    /**
     * Whether the amount is the full hold amount.
     *
     * @param float $amount
     * @param float $holdAmount
     * @return bool
     */
    public function isFullAmount(float $amount, float $holdAmount): bool
    {
        return abs($holdAmount - $amount) <= self::AMOUNT_TOLERANCE;
    }
}
