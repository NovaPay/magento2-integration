<?php
/**
 * Copyright © 2026 NovaPay. All rights reserved.
 * Licensed under the MIT License. See LICENSE for details.
 */
declare(strict_types=1);

namespace NovaPay\Payment\Gateway;

use NovaPay\Payment\Api\SignatureServiceInterface;
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
class SignatureService implements SignatureServiceInterface
{
    private const ALGORITHM = OPENSSL_ALGO_SHA256;

    /**
     * Sign the exact raw body string with the merchant's RSA private key; base64-encode for the
     * `x-sign` header.
     *
     * @param string $rawBody exact bytes that will be (or were) sent as the HTTP body
     * @param string $privateKeyPem PEM-encoded RSA private key (merchant's, decrypted)
     * @return string base64-encoded signature
     * @throws InvalidKeyException the key is missing, malformed, or rejected by openssl
     */
    public function sign(string $rawBody, string $privateKeyPem): string
    {
        $key = $this->loadPrivateKey($privateKeyPem);

        $signature = '';
        $signed = openssl_sign($rawBody, $signature, $key, self::ALGORITHM);
        if ($signed === false) {
            throw new InvalidKeyException(
                __('Unable to sign the NovaPay request: the configured private key was rejected by openssl.')
            );
        }

        return base64_encode($signature);
    }

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
    public function verify(string $rawBody, string $signature, string $publicKeyPem): bool
    {
        $key = $this->loadPublicKey($publicKeyPem);

        $decodedSignature = base64_decode($signature, true);
        if ($decodedSignature === false) {
            return false;
        }

        $result = openssl_verify($rawBody, $decodedSignature, $key, self::ALGORITHM);

        return $result === 1;
    }

    /**
     * Load a PEM-encoded RSA private key, failing loudly on rejection.
     *
     * `openssl_pkey_get_private()` happily loads any well-formed asymmetric private key — EC, DSA,
     * Ed25519 — not only RSA; NovaPay's protocol is RSA-SHA256 specifically ({@see ALGORITHM}), and
     * `openssl_sign()` would not itself reject a non-RSA key (it would just silently produce a
     * signature of the wrong type, one NovaPay's RSA verification could never accept). Asserting the
     * key type here — rather than trusting the config field's label — keeps this "fail loudly on a
     * bad key" the whole way, not just for keys openssl itself refuses outright.
     *
     * @param string $pem
     * @return \OpenSSLAsymmetricKey
     * @throws InvalidKeyException
     */
    private function loadPrivateKey(string $pem): \OpenSSLAsymmetricKey
    {
        if (trim($pem) === '') {
            throw new InvalidKeyException(__('No NovaPay RSA key is configured.'));
        }

        $key = openssl_pkey_get_private($pem);
        if ($key === false) {
            throw new InvalidKeyException(
                __('The configured NovaPay RSA key is invalid or unreadable.')
            );
        }

        $details = openssl_pkey_get_details($key);
        if ($details === false || ($details['type'] ?? null) !== OPENSSL_KEYTYPE_RSA) {
            throw new InvalidKeyException(
                __('The configured NovaPay private key is not an RSA key.')
            );
        }

        return $key;
    }

    /**
     * Load a PEM-encoded RSA public key, failing loudly on rejection.
     *
     * @param string $pem
     * @return \OpenSSLAsymmetricKey
     * @throws InvalidKeyException
     */
    private function loadPublicKey(string $pem): \OpenSSLAsymmetricKey
    {
        if (trim($pem) === '') {
            throw new InvalidKeyException(__('No NovaPay RSA key is configured.'));
        }

        $key = openssl_pkey_get_public($pem);
        if ($key === false) {
            throw new InvalidKeyException(
                __('The configured NovaPay RSA key is invalid or unreadable.')
            );
        }

        return $key;
    }
}
