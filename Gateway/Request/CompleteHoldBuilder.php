<?php
/**
 * Copyright © 2026 NovaPay. All rights reserved.
 * Licensed under the MIT License. See LICENSE for details.
 */
declare(strict_types=1);

namespace NovaPay\Payment\Gateway\Request;

use NovaPay\Payment\Gateway\Config;

/**
 * `POST /v1/complete-hold` payload (docs/novapay-api-reference.md "3. Complete hold").
 *
 * Expected `$data` keys:
 * - session_id (string, required)
 * - amount (float, optional) — omit for a full capture; when given (partial capture), must be
 *   > 0 and < the held amount (caller's responsibility to validate against the hold)
 * - operation_id (string, required when amount is given) — becomes `operations[0].id`: the numeric
 *   NovaPay operation id from the `/v1/payment` response (NovaPay, 2026-10-12: not `external_id`).
 * - recipient_identifier (string, required when amount is given) — ЄДРПОУ/ІПН of the actual recipient
 * - store_id (int, optional)
 *
 * Live-proven: a partial complete-hold sending the amount in BOTH places — top-level `amount` AND
 * `operations[0].amount` — is rejected by NovaPay with HTTP 400 `CorruptedRequestError` ("request
 * is incorrect"), even though the documented OpenAPI schema marks the top-level `amount` as
 * optional and independent of `operations[]`. Live-probed on the test merchant (order 000000046,
 * session bc10761b-4ddf-4069-915b-68252ed3864c): omitting the top-level `amount` entirely and
 * sending it only inside `operations[0].amount` succeeds (HTTP 200) — confirmed by the session's
 * `/v1/get-status` afterwards reporting `status: "paid"` for the partial amount. This builder
 * therefore never sends a top-level `amount` alongside `operations` — the two are mutually
 * exclusive on the wire regardless of what the OpenAPI schema alone would suggest.
 *
 * @api
 */
class CompleteHoldBuilder
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
                (string)__('session_id is required to complete a NovaPay hold.')
            );
        }

        $payload = [
            'merchant_id' => $this->config->getMerchantId($data['store_id'] ?? null),
            'session_id' => $sessionId,
        ];

        if (!isset($data['amount']) || $data['amount'] === '' || $data['amount'] === null) {
            return $payload;
        }

        if (!is_numeric($data['amount']) || (float)$data['amount'] <= 0) {
            throw new \InvalidArgumentException(
                (string)__('amount must be a positive number for a partial complete-hold.')
            );
        }

        $operationId = (string)($data['operation_id'] ?? '');
        if ($operationId === '') {
            throw new \InvalidArgumentException(
                (string)__('operation_id is required for a partial complete-hold.')
            );
        }

        $recipientIdentifier = (string)($data['recipient_identifier'] ?? '');
        if ($recipientIdentifier === '') {
            throw new \InvalidArgumentException(
                (string)__('recipient_identifier is required for a partial complete-hold.')
            );
        }

        // Deliberately no top-level `amount` alongside `operations` — see the class docblock
        // ("Live-proven"): NovaPay rejects the request (400 CorruptedRequestError) when the amount
        // is present in both places, and accepts it when only `operations[0].amount` carries it.
        $payload['operations'] = [
            [
                // Numeric NovaPay operation id, not `external_id` (NovaPay, 2026-10-12).
                'id' => $operationId,
                'amount' => (float)$data['amount'],
                'recipient_identifier' => $recipientIdentifier,
            ],
        ];

        return $payload;
    }
}
