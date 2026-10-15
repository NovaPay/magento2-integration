<?php
/**
 * Copyright © 2026 NovaPay. All rights reserved.
 * Licensed under the MIT License. See LICENSE for details.
 */
declare(strict_types=1);

namespace NovaPay\Payment\Gateway;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;

/**
 * A failed NovaPay complete-hold/void call, as returned by {@see ErrorMapper::map()}.
 *
 * The exception message is the admin flash message: the mapped reason, followed by
 * " (NovaPay ref: <uuid>)" when NovaPay returned a response `uuid`. The getters expose the parts
 * separately so an order history comment can be built from them
 * ({@see \NovaPay\Payment\Model\Order\GatewayFailureHistoryWriter}).
 *
 * Also used for a transport failure (no HTTP response at all): {@see isTransportFailure()} is then
 * true and there is no code, uuid or HTTP status.
 *
 * @api
 */
class GatewayRejectedException extends LocalizedException
{
    /**
     * @param Phrase $phrase admin message (reason plus the NovaPay reference, when present)
     * @param Phrase $reason the mapped reason alone, without the NovaPay reference
     * @param string|null $errorCode NovaPay `code`, or a label such as "HTTP 500" when NovaPay sent none
     * @param string|null $novaPayUuid NovaPay response `uuid`
     * @param int|null $httpStatusCode null for a transport failure
     * @param string $endpoint e.g. "/v1/void"
     * @param bool $transportFailure true when NovaPay could not be reached at all
     * @param \Exception|null $cause
     */
    public function __construct(
        Phrase $phrase,
        private readonly Phrase $reason,
        private readonly ?string $errorCode = null,
        private readonly ?string $novaPayUuid = null,
        private readonly ?int $httpStatusCode = null,
        private readonly string $endpoint = '',
        private readonly bool $transportFailure = false,
        ?\Exception $cause = null
    ) {
        parent::__construct($phrase, $cause);
    }

    /**
     * The mapped reason, without the NovaPay reference.
     *
     * @return Phrase
     */
    public function getReason(): Phrase
    {
        return $this->reason;
    }

    /**
     * NovaPay `code` (or "HTTP <status>" when the response had no code), null when unknown.
     *
     * @return string|null
     */
    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }

    /**
     * NovaPay response `uuid`, for NovaPay support.
     *
     * @return string|null
     */
    public function getNovaPayUuid(): ?string
    {
        return $this->novaPayUuid;
    }

    /**
     * HTTP status of the NovaPay response, null for a transport failure.
     *
     * @return int|null
     */
    public function getHttpStatusCode(): ?int
    {
        return $this->httpStatusCode;
    }

    /**
     * Endpoint that was called, e.g. "/v1/void".
     *
     * @return string
     */
    public function getEndpoint(): string
    {
        return $this->endpoint;
    }

    /**
     * Whether NovaPay could not be reached at all (no HTTP response).
     *
     * @return bool
     */
    public function isTransportFailure(): bool
    {
        return $this->transportFailure;
    }
}
