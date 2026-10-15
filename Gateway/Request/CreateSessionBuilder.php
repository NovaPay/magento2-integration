<?php
/**
 * Copyright © 2026 NovaPay. All rights reserved.
 * Licensed under the MIT License. See LICENSE for details.
 */
declare(strict_types=1);

namespace NovaPay\Payment\Gateway\Request;

use NovaPay\Payment\Gateway\Config;

/**
 * `POST /v1/session` payload (docs/novapay-api-reference.md "1. Create session").
 *
 * Expected `$data` keys:
 * - client_phone (string, required) — with country code, e.g. "+380..."
 * - client_first_name, client_last_name, client_patronymic (string, optional)
 * - client_email (string, optional)
 * - callback_url, success_url, fail_url (string, optional)
 * - success_redirect_timeout (int, optional)
 * - metadata (array, optional) — echoed back verbatim in postbacks
 * - store_id (int, optional) — config scope to read merchant_id from
 *
 * Optional fields are omitted from the payload entirely when not given, rather than sent empty/null —
 * the API contract only documents their presence, not empty-value semantics.
 *
 * @api
 */
class CreateSessionBuilder
{
    private const OPTIONAL_STRING_FIELDS = [
        'client_first_name',
        'client_last_name',
        'client_patronymic',
        'client_email',
        'callback_url',
        'success_url',
        'fail_url',
    ];

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
        $clientPhone = (string)($data['client_phone'] ?? '');
        if ($clientPhone === '') {
            throw new \InvalidArgumentException(
                (string)__('client_phone is required to create a NovaPay session.')
            );
        }

        $payload = [
            'merchant_id' => $this->config->getMerchantId($data['store_id'] ?? null),
            'client_phone' => $clientPhone,
        ];

        foreach (self::OPTIONAL_STRING_FIELDS as $field) {
            if (isset($data[$field]) && $data[$field] !== '') {
                $payload[$field] = (string)$data[$field];
            }
        }

        if (isset($data['success_redirect_timeout'])) {
            $payload['success_redirect_timeout'] = (int)$data['success_redirect_timeout'];
        }

        if (isset($data['metadata']) && is_array($data['metadata'])) {
            $payload['metadata'] = $data['metadata'];
        }

        return $payload;
    }
}
