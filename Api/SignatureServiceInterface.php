<?php
/**
 * Copyright © 2026 NovaPay. All rights reserved.
 * Licensed under the MIT License. See LICENSE for details.
 */
declare(strict_types=1);

namespace NovaPay\Payment\Api;

use NovaPay\Payment\Gateway\Exception\InvalidKeyException;

/**
 * RSA-SHA256 request signing / postback verification (docs/novapay-api-reference.md "Authentication").
 *
 * NovaPay signs the exact raw JSON string that is transmitted; callers must build that string once
 * (json_encode($payload, JSON_UNESCAPED_UNICODE)) and reuse it for both the HTTP body and the signature
 * input — never re-encode. See {@see \NovaPay\Payment\Gateway\Http\Client}, the single code path that
 * does so for outgoing requests.
 *
 * Stateless/key-less by design: sign()/verify() take the PEM key as an explicit argument rather than
 * binding a shared DI instance to one store's key (multi-store: private key is scoped per
 * website/store view). Callers (Http\Client) fetch the key from Gateway\Config for the relevant store.
 *
 * @api
 */
interface SignatureServiceInterface
{
    /**
     * Sign the exact raw body string with the merchant's RSA private key; base64-encode for the
     * `x-sign` header.
     *
     * @param string $rawBody exact bytes that will be (or were) sent as the HTTP body
     * @param string $privateKeyPem PEM-encoded RSA private key (merchant's, decrypted)
     * @return string base64-encoded signature
     * @throws InvalidKeyException the key is missing, malformed, or rejected by openssl
     */
    public function sign(string $rawBody, string $privateKeyPem): string;

    /**
     * Verify a base64 `x-sign` signature against the raw body using NovaPay's RSA public key.
     *
     * @param string $rawBody exact raw bytes as received (postback) — do not re-encode/re-serialize
     * @param string $signature base64-encoded signature from the `x-sign` header
     * @param string $publicKeyPem PEM-encoded RSA public key (NovaPay's)
     * @return bool true only if the signature verifies; false for a well-formed but non-matching
     *     signature (e.g. tampered body) — never throws for that case, only for an invalid key/input
     * @throws InvalidKeyException the public key is missing, malformed, or rejected by openssl
     */
    public function verify(string $rawBody, string $signature, string $publicKeyPem): bool;
}
