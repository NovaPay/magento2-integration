<?php
/**
 * Copyright © 2026 NovaPay. All rights reserved.
 * Licensed under the MIT License. See LICENSE for details.
 */
declare(strict_types=1);

namespace NovaPay\Payment\Gateway\Http;

/**
 * The single masking rule shared by every place that ever prints NovaPay payment data that might
 * carry a PAN-shaped digit run: masks any 12-19-digit run as first 6 + `******` + last 4 (e.g.
 * `522712******0426`). A `client_phone` value is masked separately, by field KEY rather than digit
 * count (see {@see PHONE_KEYS}/{@see maskPhone()}) - a raw local-format phone number falls below
 * the 12-digit PAN-run threshold and would otherwise pass through this blind regex unmasked.
 *
 * Used by {@see \NovaPay\Payment\Block\Adminhtml\Order\View\NovaPayInfo::getMaskedPan()} directly
 * via {@see mask()}, and by {@see \NovaPay\Payment\Gateway\Http\Client::request()} (every logged
 * request/response body) indirectly via {@see maskJson()} - see that method's docblock for why the
 * two are not the same call. Plain, DI-injected instance methods rather than `final`/`static`
 * (Magento coding standard discourages both).
 *
 * @api
 */
class PanMasker
{
    /**
     * Field names (case-insensitive, matched at any nesting depth) known to carry a card number.
     * Checked first by {@see maskArray()}: the whole value is masked by {@see maskPan()} regardless
     * of its formatting, so a PAN written with spaces or dashes (`4134 1790 0100 0184`, no
     * 12-19-digit run) is masked too.
     */
    private const PAN_KEYS = ['pan', 'card_number', 'masked_pan'];

    /**
     * Field names (case-insensitive, matched at any nesting depth) that carry NovaPay or bank
     * identifiers, not card data. {@see maskArray()} leaves their values as they are, so a 12-digit
     * operation id (`operations[].id`, e.g. `120191346704`) stays readable in the log and can be
     * reconciled with NovaPay support.
     */
    private const ID_KEYS = [
        'id',
        'transaction_id',
        'session_id',
        'external_id',
        'payment_id',
        'operation_id',
        'rrn',
        'approval_code',
        'approval',
    ];

    /**
     * Field names (case-insensitive, matched at any nesting depth) known to carry a phone number,
     * masked by {@see maskPhone()} regardless of the value's format/length. The blanket
     * {@see mask()} pass only recognizes a 12-19-digit run, so a raw local-format Ukrainian number
     * (e.g. `0631234567`, 10 digits) would otherwise pass through unmasked (live-proven with phone
     * normalization disabled).
     */
    private const PHONE_KEYS = ['client_phone'];

    /**
     * Minimum length {@see maskPhone()} will partially mask (keeping first 3 + last 4 characters,
     * per {@see maskPhone()}'s own docblock); anything shorter is masked in full rather than risk
     * overlapping/negative-length "kept" segments revealing the whole value.
     */
    private const MIN_PHONE_LENGTH_FOR_PARTIAL_MASK = 8;

    /**
     * Mask any 12-19-digit run in `$text` as first 6 + `******` + last 4. Correct and sufficient for
     * a single already-identified PAN-ish string (e.g. the stored `novapay_card_pan` field), but too
     * broad for a whole request/response body - see {@see maskJson()}, which
     * {@see \NovaPay\Payment\Gateway\Http\Client} uses instead to avoid this method's blind-regex
     * behavior on structured JSON.
     *
     * @param string $text
     * @return string
     */
    public function mask(string $text): string
    {
        return (string)preg_replace_callback(
            '/\d{12,19}/',
            static fn (array $matches): string => substr($matches[0], 0, 6)
                . str_repeat('*', max(strlen($matches[0]) - 10, 0))
                . substr($matches[0], -4),
            $text
        );
    }

    /**
     * Mask a JSON request/response body for logging: {@see maskArray()} masks every
     * {@see PAN_KEYS} leaf in full and every `client_phone` leaf by KEY (regardless of digit
     * count/format), leaves {@see ID_KEYS} values as they are, and applies the blanket digit-run
     * {@see mask()} pass to every other STRING leaf at any nesting depth (this is what re-masks e.g.
     * a PAN echoed under a field name not in {@see PAN_KEYS}). Numeric NovaPay ids decode as PHP
     * ints, never strings, so they are never touched. Falls back to the broad,
     * "when in doubt, mask" {@see mask()} regex over the raw, unparsed string whenever `$rawJson`
     * does not decode to a JSON array/object - this also avoids a latent bug a blind regex would
     * have: it could turn a valid unquoted JSON number into an invalid token, breaking downstream
     * re-parsing of a logged/preserved error body. maskJson() never breaks JSON syntax because it
     * re-serializes the (masked) decoded structure rather than regexing the original string.
     *
     * @param string $rawJson
     * @return string
     */
    public function maskJson(string $rawJson): string
    {
        $decoded = json_decode($rawJson, true);
        if (!is_array($decoded)) {
            return $this->mask($rawJson);
        }

        $encoded = json_encode($this->maskArray($decoded), JSON_UNESCAPED_UNICODE);

        return $encoded !== false ? $encoded : $this->mask($rawJson);
    }

    /**
     * Recursively mask leaf values.
     *
     * Order of the key rules (keys matched case-insensitively): a {@see PAN_KEYS} scalar is masked
     * in full by {@see maskPan()}; a {@see PHONE_KEYS} scalar by {@see maskPhone()}; an
     * {@see ID_KEYS} value is kept; every other STRING leaf gets the blanket digit-run {@see mask()}
     * pass. Non-string scalars under other keys (numeric JSON leaves) are never touched.
     *
     * @param array $data
     * @return array
     */
    private function maskArray(array $data): array
    {
        foreach ($data as $key => $value) {
            $name = is_string($key) ? strtolower($key) : null;
            if (is_array($value)) {
                $data[$key] = $this->maskArray($value);
            } elseif ($name !== null && in_array($name, self::PAN_KEYS, true) && is_scalar($value)) {
                $data[$key] = $this->maskPan((string)$value);
            } elseif ($name !== null && in_array($name, self::PHONE_KEYS, true) && is_scalar($value)) {
                $data[$key] = $this->maskPhone((string)$value);
            } elseif ($name !== null && in_array($name, self::ID_KEYS, true)) {
                continue;
            } elseif (is_string($value)) {
                $data[$key] = $this->mask($value);
            }
        }

        return $data;
    }

    /**
     * Mask a value known to be a card number, whatever its formatting: a value NovaPay already
     * masked (contains `*`) only gets the {@see mask()} pass; otherwise the digits are taken
     * without separators and masked as first 6 + `*` + last 4 (a value with fewer than 12 digits is
     * masked in full).
     *
     * @param string $text
     * @return string
     */
    private function maskPan(string $text): string
    {
        if (str_contains($text, '*')) {
            return $this->mask($text);
        }

        $digits = (string)preg_replace('/\D/', '', $text);
        $length = strlen($digits);
        if ($length < 12) {
            return str_repeat('*', strlen($text));
        }

        return substr($digits, 0, 6) . str_repeat('*', $length - 10) . substr($digits, -4);
    }

    /**
     * Mask a phone-number value (e.g. `client_phone`) by KEY, regardless of its format/length: keeps
     * the first 3 and last 4 characters, masks everything between with `*`. Unlike {@see mask()},
     * this never looks at digit count - an international `+380...` number and a raw local-format one
     * (`0631234567`, only 10 digits - below {@see mask()}'s 12-digit minimum run length, which is how
     * this gap was found: with phone normalization disabled, such a number was logged completely
     * unmasked) are masked exactly the same way. A value shorter than
     * {@see MIN_PHONE_LENGTH_FOR_PARTIAL_MASK} is masked in full instead - partially masking
     * something that short could leave most or all of it visible.
     *
     * @param string $text
     * @return string
     */
    private function maskPhone(string $text): string
    {
        $length = strlen($text);
        if ($length < self::MIN_PHONE_LENGTH_FOR_PARTIAL_MASK) {
            return str_repeat('*', $length);
        }

        return substr($text, 0, 3) . str_repeat('*', $length - 7) . substr($text, -4);
    }
}
