<?php
/**
 * Copyright © 2026 NovaPay. All rights reserved.
 * Licensed under the MIT License. See LICENSE for details.
 */
declare(strict_types=1);

namespace NovaPay\Payment\Model;

/**
 * Validates and normalizes Ukrainian phone numbers to the `+380XXXXXXXXX` form NovaPay's API
 * documents for `client_phone` (docs/novapay-api-reference.md "1. Create session").
 *
 * Accepted input forms, after stripping everything but ASCII digits (0-9) and a leading `+` —
 * this strips ASCII and Unicode whitespace (e.g. NBSP U+00A0) as well as non-ASCII decimal digits
 * (e.g. full-width ０ U+FF10, Arabic-Indic ٥ U+0665), see normalize(): `+380XXXXXXXXX`,
 * `380XXXXXXXXX`, `0XXXXXXXXX` (nine digits after the country code / trunk `0`). Anything else
 * (wrong country code, wrong digit count, non-numeric) is invalid.
 *
 * The single source of truth for the mask; mirrored in
 * view/frontend/web/js/view/payment/method-renderer/novapay-method.js for the checkout UX —
 * keep both in sync.
 *
 * @api
 */
class PhoneNumberValidator
{
    private const NORMALIZED_PATTERN = '/^(?:\+?380|0)(\d{9})$/';

    /**
     * Whether `$phone` is a valid Ukrainian phone number.
     *
     * @param string $phone
     * @return bool
     */
    public function isValid(string $phone): bool
    {
        try {
            $this->normalize($phone);

            return true;
        } catch (\InvalidArgumentException) {
            return false;
        }
    }

    /**
     * Normalize `$phone` to its canonical `+380XXXXXXXXX` form.
     *
     * @param string $phone
     * @return string the phone in canonical `+380XXXXXXXXX` form
     * @throws \InvalidArgumentException `$phone` is not a valid Ukrainian phone number
     */
    public function normalize(string $phone): string
    {
        // Keep only ASCII digits (0-9, explicitly — NOT \d, which under /u also matches Unicode
        // decimal digits like full-width ０ U+FF10 or Arabic-Indic ٥ U+0665, silently keeping them
        // instead of stripping them) and a leading "+". /u is still required so multi-byte
        // characters (e.g. NBSP U+00A0, thin space, U+FEFF, U+3000) are stripped as whole code
        // points rather than leaving stray bytes behind. Must strip exactly the same set as
        // novapay-method.js's isValidUaPhone() — JS's \d is always ASCII-only — or a phone can pass
        // the JS check and still fail here after the order is already placed.
        $stripped = (string)preg_replace('/[^0-9+]+/u', '', $phone);

        if (preg_match(self::NORMALIZED_PATTERN, $stripped, $matches) !== 1) {
            throw new \InvalidArgumentException(
                (string)__('The phone number is not a valid Ukrainian phone number.')
            );
        }

        return '+380' . $matches[1];
    }
}
