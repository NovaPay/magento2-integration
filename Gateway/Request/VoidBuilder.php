<?php
/**
 * Copyright © 2026 NovaPay. All rights reserved.
 * Licensed under the MIT License. See LICENSE for details.
 */
declare(strict_types=1);

namespace NovaPay\Payment\Gateway\Request;

use NovaPay\Payment\Gateway\Config;

/**
 * `POST /v1/void` payload (docs/novapay-api-reference.md "4. Void / refund").
 *
 * One endpoint, three scenarios, all with the same builder input shape:
 * - Cancel hold / full refund: omit `operations` entirely.
 * - Partial refund (per line): `operations` = list of ['id' => string, 'refund_amount' => float].
 *   `id` is the numeric NovaPay operation id (the `/v1/payment` response `id`, or the
 *   post-completion `transaction_id` after a partial complete-hold), not the `external_id`;
 *   NovaPay confirmed (2026-10-12) that the spec's `payments[].external_id` is wrong for refunds.
 *
 * Expected `$data` keys:
 * - session_id (string, required)
 * - operations (array, optional) — list of ['id' => string, 'refund_amount' => float > 0]. Either
 *   omit the key entirely (cancel hold / full refund), or pass a non-empty array — an explicitly
 *   *present but empty/falsy* `operations` value (e.g. `[]`) is rejected rather than silently
 *   treated the same as "key omitted", which would otherwise send a FULL refund/cancel when a
 *   partial one was actually intended (a caller bug producing an empty operations list must never
 *   be swallowed into "refund everything").
 * - store_id (int, optional)
 *
 * `operations[].refund_amount` is sent as a STRING, e.g. `"39.00"`, matching the documented OpenAPI
 * schema for `POST /v1/void` (`refund_amount: {type: "string"}`, example `"1000"`) — formatted with
 * exactly 2 decimal places (no thousands separator), matching every `amount`-shaped value NovaPay
 * itself returns as a string on `/v1/get-status` (e.g. `"44.00"`, `"30.00"`), since the doc's own
 * `"1000"` example is a round number and does not by itself confirm whether decimals are dropped.
 *
 * Business rules such as "partial hold cancel is not supported" or "refund_amount must not exceed
 * the paid line total" are enforced by the caller, not here — this builder only guarantees a
 * well-formed payload.
 *
 * @api
 */
class VoidBuilder
{
    /**
     * @param Config $config
     */
    public function __construct(private readonly Config $config)
    {
    }

    /**
     * @inheritDoc
     */
    public function build(array $data): array
    {
        $sessionId = (string)($data['session_id'] ?? '');
        if ($sessionId === '') {
            throw new \InvalidArgumentException(
                (string)__('session_id is required to void a NovaPay session.')
            );
        }

        $payload = [
            'merchant_id' => $this->config->getMerchantId($data['store_id'] ?? null),
            'session_id' => $sessionId,
        ];

        if (!array_key_exists('operations', $data)) {
            // Key omitted entirely: cancel hold / full refund.
            return $payload;
        }

        $operations = $data['operations'];
        if (!is_array($operations) || $operations === []) {
            // Key explicitly present but not a non-empty array — never silently treated the same as
            // "omitted" (see the class docblock): a caller bug producing an empty/falsy operations
            // value must not be swallowed into an unintended full refund/cancel.
            throw new \InvalidArgumentException(
                (string)__('operations must be a non-empty array of {id, refund_amount} when present.')
            );
        }

        $payload['operations'] = array_map([$this, 'buildOperation'], $operations);

        return $payload;
    }

    /**
     * Validate and normalize a single per-line refund operation.
     *
     * @param mixed $operation
     * @return array{id: string, refund_amount: string}
     */
    private function buildOperation(mixed $operation): array
    {
        if (!is_array($operation) || !isset($operation['id'], $operation['refund_amount'])) {
            throw new \InvalidArgumentException(
                (string)__('Each NovaPay void operation requires id and refund_amount.')
            );
        }

        $refundAmount = (float)$operation['refund_amount'];
        if ($refundAmount <= 0) {
            throw new \InvalidArgumentException(
                (string)__('refund_amount must be a positive number.')
            );
        }

        return [
            // Numeric NovaPay operation id, not `external_id` (NovaPay, 2026-10-12).
            'id' => (string)$operation['id'],
            // Sent as a string per the documented schema — see the class docblock.
            'refund_amount' => number_format($refundAmount, 2, '.', ''),
        ];
    }
}
