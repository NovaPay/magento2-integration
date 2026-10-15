<?php
/**
 * Copyright © 2026 NovaPay. All rights reserved.
 * Licensed under the MIT License. See LICENSE for details.
 */
declare(strict_types=1);

namespace NovaPay\Payment\Setup\Patch\Data;

use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Sales\Model\Order as SalesOrder;
use NovaPay\Payment\Model\Order\StatusCode;
use NovaPay\Payment\Setup\OrderStatusInstaller;

/**
 * Also assigns novapay_failed to the `pending_payment` state.
 *
 * A NovaPay `failed` session status no longer cancels the order: the customer can retry within the
 * same NovaPay session, so {@see \NovaPay\Payment\Model\StatusMapper} now maps `failed` to state
 * `pending_payment` with the novapay_failed label. The `canceled` assignment made by
 * {@see AddFailedAndRefundedOrderStatuses} is kept for orders canceled by the earlier behavior.
 *
 * The new assignment is not the default status of `pending_payment` (core's `pending_payment`
 * stays the default) and is visible on the storefront, like the existing novapay_failed row.
 * Idempotent: an existing status keeps its label and the assignment is an
 * INSERT ... ON DUPLICATE KEY UPDATE (see {@see OrderStatusInstaller}).
 */
class AssignFailedStatusToPendingPaymentState implements DataPatchInterface
{
    /**
     * @param OrderStatusInstaller $statusInstaller
     */
    public function __construct(
        private readonly OrderStatusInstaller $statusInstaller
    ) {
    }

    /**
     * @inheritdoc
     */
    public function apply(): self
    {
        $this->statusInstaller->install(
            StatusCode::FAILED,
            'NovaPay Payment Failed',
            [SalesOrder::STATE_PENDING_PAYMENT]
        );

        return $this;
    }

    /**
     * @inheritdoc
     */
    public static function getDependencies(): array
    {
        return [AddFailedAndRefundedOrderStatuses::class, UnassignPendingStatusFromPendingPaymentState::class];
    }

    /**
     * @inheritdoc
     */
    public function getAliases(): array
    {
        return [];
    }
}
