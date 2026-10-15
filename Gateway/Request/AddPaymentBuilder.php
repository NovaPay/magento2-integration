<?php
/**
 * Copyright © 2026 NovaPay. All rights reserved.
 * Licensed under the MIT License. See LICENSE for details.
 */
declare(strict_types=1);

namespace NovaPay\Payment\Gateway\Request;

use NovaPay\Payment\Gateway\Config;

/**
 * `POST /v1/payment` payload (docs/novapay-api-reference.md "2. Add payment").
 *
 * Expected `$data` keys:
 * - session_id (string, required)
 * - amount (float, required)
 * - external_id (string, required by this builder) — the API marks it optional, but the doc notes
 *   "always send it" (needed to match orders on postback via `payments[].external_id`); this module
 *   always sends the order increment_id, so we enforce it here.
 * - use_hold (bool, optional, default false) — always sent explicitly (direct vs hold charge model)
 * - identifier (string, optional) — recipient ЄДРПОУ if payee differs from merchant
 * - products (array, optional) — list of ['description' => string, 'count' => number, 'price' => float]
 * - store_id (int, optional)
 *
 * @api
 */
class AddPaymentBuilder
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
                (string)__('session_id is required to add a NovaPay payment.')
            );
        }

        if (!isset($data['amount']) || !is_numeric($data['amount'])) {
            throw new \InvalidArgumentException(
                (string)__('amount is required to add a NovaPay payment.')
            );
        }

        $externalId = (string)($data['external_id'] ?? '');
        if ($externalId === '') {
            throw new \InvalidArgumentException(
                (string)__('external_id is required to add a NovaPay payment.')
            );
        }

        $payload = [
            'merchant_id' => $this->config->getMerchantId($data['store_id'] ?? null),
            'session_id' => $sessionId,
            'amount' => (float)$data['amount'],
            'external_id' => $externalId,
            'use_hold' => (bool)($data['use_hold'] ?? false),
        ];

        if (isset($data['identifier']) && $data['identifier'] !== '') {
            $payload['identifier'] = (string)$data['identifier'];
        }

        if (isset($data['products']) && is_array($data['products'])) {
            $payload['products'] = array_map(
                [$this, 'buildProductLine'],
                $data['products']
            );
        }

        return $payload;
    }

    /**
     * Validate and normalize a single order-line entry.
     *
     * @param mixed $line
     * @return array{description: string, count: float, price: float}
     */
    private function buildProductLine(mixed $line): array
    {
        if (!is_array($line)
            || !isset($line['description'], $line['count'], $line['price'])
        ) {
            throw new \InvalidArgumentException(
                (string)__('Each NovaPay product line requires description, count and price.')
            );
        }

        return [
            'description' => (string)$line['description'],
            'count' => (float)$line['count'],
            'price' => (float)$line['price'],
        ];
    }
}
