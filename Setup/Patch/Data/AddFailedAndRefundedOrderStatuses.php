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
 * Registers novapay_failed (state canceled) and novapay_refunded (state closed), the default labels
 * for the `failed` and `voided` NovaPay session statuses, so a NovaPay-failed payment is
 * distinguishable from a manual cancel and a NovaPay refund from any other close.
 *
 * A separate patch (not an edit of {@see AddOrderStatuses}) because that patch is already recorded
 * as applied on existing installs; this one runs on their next `setup:upgrade`.
 */
class AddFailedAndRefundedOrderStatuses implements DataPatchInterface
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
            [SalesOrder::STATE_CANCELED]
        );
        $this->statusInstaller->install(
            StatusCode::REFUNDED,
            'Refunded via NovaPay',
            [SalesOrder::STATE_CLOSED]
        );

        return $this;
    }

    /**
     * @inheritdoc
     */
    public static function getDependencies(): array
    {
        return [AddOrderStatuses::class];
    }

    /**
     * @inheritdoc
     */
    public function getAliases(): array
    {
        return [];
    }
}
