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
 * Registers the custom order statuses NovaPay drives via postback: novapay_pending,
 * novapay_holded, novapay_paid, novapay_expired.
 *
 * Idempotent via {@see OrderStatusInstaller} (load-by-code before insert, upsert state assignment).
 * Safe to re-run (patch tracking already guarantees apply() runs once, this is defense in depth).
 *
 * novapay_pending is assigned to state `new` only (the state InitializeCommand places a NovaPay
 * order in). Installs that ran an earlier version of this patch also had it assigned to
 * `pending_payment`; {@see UnassignPendingStatusFromPendingPaymentState} removes that assignment.
 *
 * novapay_failed / novapay_refunded are added by the follow-up patch
 * {@see AddFailedAndRefundedOrderStatuses}, not here, because this patch is already applied on
 * existing installs.
 */
class AddOrderStatuses implements DataPatchInterface
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
        foreach ($this->getStatusDefinitions() as $definition) {
            $this->statusInstaller->install($definition['code'], $definition['label'], $definition['state']);
        }

        return $this;
    }

    /**
     * NovaPay custom order statuses and the order state(s) each is valid for.
     *
     * @return array<int, array{code: string, label: string, state: string[]}>
     */
    private function getStatusDefinitions(): array
    {
        return [
            [
                'code' => StatusCode::PENDING,
                'label' => 'Pending NovaPay Payment',
                'state' => [SalesOrder::STATE_NEW],
            ],
            [
                'code' => StatusCode::HOLDED,
                'label' => 'NovaPay Hold Authorized',
                'state' => [SalesOrder::STATE_PROCESSING],
            ],
            [
                'code' => StatusCode::PAID,
                'label' => 'Paid via NovaPay',
                'state' => [SalesOrder::STATE_PROCESSING],
            ],
            [
                'code' => StatusCode::EXPIRED,
                'label' => 'NovaPay Session Expired',
                'state' => [SalesOrder::STATE_CANCELED],
            ],
        ];
    }

    /**
     * @inheritdoc
     */
    public static function getDependencies(): array
    {
        return [];
    }

    /**
     * @inheritdoc
     */
    public function getAliases(): array
    {
        return [];
    }
}
