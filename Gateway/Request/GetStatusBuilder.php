<?php
/**
 * Copyright © 2026 NovaPay. All rights reserved.
 * Licensed under the MIT License. See LICENSE for details.
 */
declare(strict_types=1);

namespace NovaPay\Payment\Gateway\Request;

use NovaPay\Payment\Gateway\Config;

/**
 * `POST /v1/get-status` payload (docs/novapay-api-reference.md "5. Get status"). Used by the
 * cron fallback to recover from missed postbacks.
 *
 * Expected `$data` keys:
 * - session_id (string, required)
 * - store_id (int, optional)
 *
 * @api
 */
class GetStatusBuilder
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
                (string)__('session_id is required to get a NovaPay session status.')
            );
        }

        return [
            'merchant_id' => $this->config->getMerchantId($data['store_id'] ?? null),
            'session_id' => $sessionId,
        ];
    }
}
