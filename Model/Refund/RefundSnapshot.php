<?php
/**
 * Copyright © 2026 NovaPay. All rights reserved.
 * Licensed under the MIT License. See LICENSE for details.
 */
declare(strict_types=1);

namespace NovaPay\Payment\Model\Refund;

/**
 * How much of a NovaPay session was refunded, as read from `operations[]` (a `/v1/get-status`
 * response, or a postback that carries operations) by {@see NovaPayRefundState}, or from Magento's own
 * ledger when NovaPay could not be asked.
 */
class RefundSnapshot
{
    private const AMOUNT_TOLERANCE = 0.01;

    /**
     * @param float $amount captured amount of the operations
     * @param float $refunded refunded amount of the operations (cumulative)
     * @param array $operations the raw `operations[]` entries the amounts were read from, `[]` when
     *     the snapshot comes from Magento's ledger
     */
    public function __construct(
        private readonly float $amount,
        private readonly float $refunded,
        private readonly array $operations = []
    ) {
    }

    /**
     * Captured amount of the operations.
     *
     * @return float
     */
    public function getAmount(): float
    {
        return $this->amount;
    }

    /**
     * Refunded amount of the operations.
     *
     * @return float
     */
    public function getRefunded(): float
    {
        return $this->refunded;
    }

    /**
     * The raw `operations[]` entries, `[]` for a ledger-based snapshot.
     *
     * @return array
     */
    public function getOperations(): array
    {
        return $this->operations;
    }

    /**
     * Whether everything captured was refunded (within the rounding tolerance).
     *
     * @return bool
     */
    public function isFullyRefunded(): bool
    {
        return $this->refunded >= $this->amount - self::AMOUNT_TOLERANCE;
    }

    /**
     * Whether a refund was made at all (more than the rounding tolerance).
     *
     * @return bool
     */
    public function hasRefund(): bool
    {
        return $this->refunded > self::AMOUNT_TOLERANCE;
    }
}
