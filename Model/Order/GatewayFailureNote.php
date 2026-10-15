<?php
/**
 * Copyright © 2026 NovaPay. All rights reserved.
 * Licensed under the MIT License. See LICENSE for details.
 */
declare(strict_types=1);

namespace NovaPay\Payment\Model\Order;

use NovaPay\Payment\Gateway\GatewayRejectedException;

/**
 * One failed NovaPay gateway call, waiting to be written to the order history by
 * {@see GatewayFailureHistoryWriter}.
 */
class GatewayFailureNote
{
    public const OPERATION_REFUND = 'refund';
    public const OPERATION_CAPTURE = 'capture';
    public const OPERATION_VOID = 'void';

    /**
     * @param int $orderId order entity id
     * @param string $operation one of the OPERATION_* constants
     * @param float|null $amount amount in the order base currency; null for a hold release
     * @param string $currencyCode order base currency code
     * @param GatewayRejectedException $error the mapped NovaPay error
     */
    public function __construct(
        private readonly int $orderId,
        private readonly string $operation,
        private readonly ?float $amount,
        private readonly string $currencyCode,
        private readonly GatewayRejectedException $error
    ) {
    }

    /**
     * Order entity id.
     *
     * @return int
     */
    public function getOrderId(): int
    {
        return $this->orderId;
    }

    /**
     * One of the OPERATION_* constants.
     *
     * @return string
     */
    public function getOperation(): string
    {
        return $this->operation;
    }

    /**
     * Amount in the order base currency, null for a hold release.
     *
     * @return float|null
     */
    public function getAmount(): ?float
    {
        return $this->amount;
    }

    /**
     * Order base currency code.
     *
     * @return string
     */
    public function getCurrencyCode(): string
    {
        return $this->currencyCode;
    }

    /**
     * The mapped NovaPay error.
     *
     * @return GatewayRejectedException
     */
    public function getError(): GatewayRejectedException
    {
        return $this->error;
    }
}
