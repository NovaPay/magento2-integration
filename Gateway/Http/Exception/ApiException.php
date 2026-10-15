<?php
/**
 * Copyright © 2026 NovaPay. All rights reserved.
 * Licensed under the MIT License. See LICENSE for details.
 */
declare(strict_types=1);

namespace NovaPay\Payment\Gateway\Http\Exception;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;

/**
 * Base type for every failure raised by {@see \NovaPay\Payment\Gateway\Http\Client}. Callers
 * (controllers, gateway commands) can catch this single type and show a generic translated message
 * to the merchant/customer while the specific subclass + getEndpoint()/getHttpStatusCode() are used
 * for logging — never expose the raw NovaPay response body to checkout/admin UI.
 */
abstract class ApiException extends LocalizedException
{
    /**
     * @param Phrase $phrase
     * @param string $endpoint API endpoint called, e.g. "/v1/session"
     * @param int|null $httpStatusCode null for transport-level failures (timeout, DNS, TLS, ...)
     * @param string|null $responseBody raw response body, for logging only; may be null
     * @param \Exception|null $cause
     */
    public function __construct(
        Phrase $phrase,
        private readonly string $endpoint,
        private readonly ?int $httpStatusCode = null,
        private readonly ?string $responseBody = null,
        ?\Exception $cause = null
    ) {
        parent::__construct($phrase, $cause);
    }

    /**
     * Endpoint that was called, e.g. "/v1/session".
     *
     * @return string
     */
    public function getEndpoint(): string
    {
        return $this->endpoint;
    }

    /**
     * HTTP status code, or null for a transport-level failure (timeout, DNS, TLS, ...).
     *
     * @return int|null
     */
    public function getHttpStatusCode(): ?int
    {
        return $this->httpStatusCode;
    }

    /**
     * Raw response body (for logging only), or null when none was received.
     *
     * @return string|null
     */
    public function getResponseBody(): ?string
    {
        return $this->responseBody;
    }
}
