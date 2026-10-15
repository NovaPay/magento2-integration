<?php
/**
 * Copyright © 2026 NovaPay. All rights reserved.
 * Licensed under the MIT License. See LICENSE for details.
 */
declare(strict_types=1);

namespace NovaPay\Payment\Model\Postback;

use Psr\Log\LoggerInterface;

/**
 * Parses the v3 postback schema (current since 01.10.2025).
 *
 * Documented fields: `id` (session_id), `status`, `paytype`, `RRN`, `APPROVAL`,
 * `card_details {pan, card_bank, card_country, card_type}`, `payments: [{external_id, amount,
 * products[]}]`, `metadata`. All fields other than `id` and `status` are optional — a postback for
 * a card payment carries `card_details`, a wallet/app payment may not.
 *
 * Live-evidence divergence: a real `POST /v1/get-status` response was observed using a flat,
 * lowercase shape for three fields instead of the documented one — lowercase top-level `rrn` (not
 * `RRN`), lowercase top-level `approval_code` (not `APPROVAL`), and a top-level `pan` (not
 * `card_details.pan`). {@see extractFirstPresent()} therefore tries the documented name first, then
 * the live-observed one, so either shape is accepted. `card_type`/`card_bank`/`card_country` get
 * the same documented-first, flat-fallback treatment; this divergence is based on live evidence
 * only, not confirmed as NovaPay's documented, contractual v3 schema.
 *
 * The module additionally reads an `operations` array, used by
 * {@see \NovaPay\Payment\Model\StatusMapper::isPartialVoid()} to distinguish "voided (single
 * operation, partial)" from "voided (session fully)". This field is NOT documented in the v3
 * *postback* payload field list, but IS documented (and live-confirmed) on the `/v1/get-status`
 * response, which is parsed by this same class (see {@see \NovaPay\Payment\Cron\SyncStatus}) —
 * each entry shaped `{transaction_id, external_id, amount, refunded_amount, status}`, NOT the
 * `{id, refund_amount}` shape this module sends to `POST /v1/void` — the two must not be confused.
 * `isPartialVoid()` reads `amount`/`refunded_amount` per entry; this class only captures the array
 * as-is.
 *
 * {@see ParsedPostback::getAmount()} is populated the same documented-first, live-observed-fallback
 * way as the RRN/approval/pan fields above: the documented v3 postback schema carries the amount at
 * `payments[0].amount`; a live `/v1/get-status` response (parsed by this same class, per
 * {@see \NovaPay\Payment\Cron\SyncStatus}) instead carries it as a top-level `amount` field (e.g.
 * `"78.00"`) with no `payments` key at all. {@see extractAmount()} tries the documented location
 * first, then the top-level one.
 *
 * @api
 */
class V3Parser implements PostbackParserInterface
{
    /**
     * @param LoggerInterface $logger
     */
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    /**
     * @inheritDoc
     */
    public function parse(array $decoded): ?ParsedPostback
    {
        $rawId = $decoded['id'] ?? null;
        $rawStatus = $decoded['status'] ?? null;

        if (($rawId !== null && !is_scalar($rawId)) || ($rawStatus !== null && !is_scalar($rawStatus))) {
            // A malformed-but-validly-signed payload (e.g. "id"/"status" sent as an object/array)
            // must never throw — (string)$array triggers a PHP warning. Reject the whole payload
            // instead, per the parser interface contract ("never throw").
            $this->logger->warning('NovaPay v3 postback payload has a non-scalar "id" or "status" field.', [
                'event' => 'postback.malformed',
                'reason' => 'non_scalar_id_or_status',
                'id_type' => get_debug_type($rawId),
                'status_type' => get_debug_type($rawStatus),
            ]);

            return null;
        }

        $sessionId = trim((string)($rawId ?? ''));
        $status = trim((string)($rawStatus ?? ''));

        if ($sessionId === '' || $status === '') {
            $this->logger->warning('NovaPay v3 postback payload is missing required "id" or "status" field.', [
                'event' => 'postback.malformed',
                'reason' => 'missing_id_or_status',
                'has_id' => isset($decoded['id']),
                'has_status' => isset($decoded['status']),
            ]);

            return null;
        }

        $cardDetails = is_array($decoded['card_details'] ?? null) ? $decoded['card_details'] : [];
        $payments = is_array($decoded['payments'] ?? null) ? $decoded['payments'] : [];
        $operations = is_array($decoded['operations'] ?? null) ? $decoded['operations'] : null;

        return new ParsedPostback(
            $sessionId,
            $status,
            $this->extractExternalId($payments),
            $this->nullableString($decoded['paytype'] ?? null),
            $this->extractFirstPresent($decoded, ['RRN', 'rrn']),
            $this->extractFirstPresent($decoded, ['APPROVAL', 'approval_code']),
            $this->extractFirstPresent($cardDetails, ['pan'], $decoded, ['pan']),
            $this->extractFirstPresent($cardDetails, ['card_bank'], $decoded, ['card_bank']),
            $this->extractFirstPresent($cardDetails, ['card_country'], $decoded, ['card_country']),
            $this->extractFirstPresent($cardDetails, ['card_type'], $decoded, ['card_type']),
            $operations,
            $this->extractAmount($decoded, $payments),
            $this->nullableString($decoded['created_at'] ?? null)
        );
    }

    /**
     * The reported paid/held amount — `payments[0].amount` (documented postback schema) first, else
     * the top-level `amount` field (live-observed `/v1/get-status` shape — see class docblock).
     * `null` when neither is a numeric value.
     *
     * CAVEAT (not live-verified either way): `payments[0].amount` may represent the ORIGINALLY
     * AUTHORIZED/HELD amount (i.e. what `/v1/payment` was called with), not necessarily what was
     * actually CAPTURED for this specific event — whereas the top-level `amount` on a live
     * `/v1/get-status` response has been observed tracking the captured amount after a partial
     * complete-hold (order 000000046: held 78.00, `amount` became "44.00" post-completion). Callers
     * comparing this value against an invoice/order total (see
     * {@see \NovaPay\Payment\Model\OrderStatusApplier::guardAgainstPaidAmountMismatch()} /
     * {@see \NovaPay\Payment\Model\OrderStatusApplier::finalizePendingInvoice()}) should treat it as
     * "the best available signal of what was captured", not a contractually-guaranteed capture
     * amount, until NovaPay confirms the semantics of `payments[0].amount` for a real (non-get-status)
     * postback. Additionally, in a MULTI-PAYMENT session (multiple `/v1/payment` calls against one
     * `/v1/session` — not currently used by this module, which makes at most one) the top-level
     * `amount` on a `/v1/get-status` response is NOT the session total, only the amount tied to the
     * event being reported.
     *
     * @param array $decoded
     * @param array $payments
     * @return float|null
     */
    private function extractAmount(array $decoded, array $payments): ?float
    {
        $first = $payments[0] ?? null;
        if (is_array($first) && isset($first['amount']) && is_numeric($first['amount'])) {
            return (float)$first['amount'];
        }

        $topLevel = $decoded['amount'] ?? null;

        return is_numeric($topLevel) ? (float)$topLevel : null;
    }

    /**
     * Documented-name-first, live-observed-name fallback (see class docblock): returns the first
     * scalar, non-empty value found for `$primaryKeys` in `$primary`, else the first found for
     * `$fallbackKeys` in `$fallback` (defaulting to the same array as `$primary` when a single flat
     * source is being checked under two possible key names).
     *
     * @param array $primary
     * @param string[] $primaryKeys
     * @param array|null $fallback
     * @param string[] $fallbackKeys
     * @return string|null
     */
    private function extractFirstPresent(
        array $primary,
        array $primaryKeys,
        ?array $fallback = null,
        array $fallbackKeys = []
    ): ?string {
        foreach ($primaryKeys as $key) {
            $value = $this->nullableString($primary[$key] ?? null);
            if ($value !== null) {
                return $value;
            }
        }

        foreach ($fallbackKeys as $key) {
            $value = $this->nullableString(($fallback ?? [])[$key] ?? null);
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    /**
     * `payments[0].external_id` — the first entry is authoritative if present.
     *
     * @param array $payments
     * @return string|null
     */
    private function extractExternalId(array $payments): ?string
    {
        $first = $payments[0] ?? null;
        if (!is_array($first)) {
            return null;
        }

        return $this->nullableString($first['external_id'] ?? null);
    }

    /**
     * Null for a missing/empty/non-scalar value (e.g. `card_details.pan` sent as an array/object
     * — `(string)$array` triggers a PHP warning), else the stringified value.
     *
     * @param mixed $value
     * @return string|null
     */
    private function nullableString(mixed $value): ?string
    {
        if ($value === null || !is_scalar($value)) {
            return null;
        }

        $string = (string)$value;

        return $string !== '' ? $string : null;
    }
}
