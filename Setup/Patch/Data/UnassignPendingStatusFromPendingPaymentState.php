<?php
/**
 * Copyright © 2026 NovaPay. All rights reserved.
 * Licensed under the MIT License. See LICENSE for details.
 */
declare(strict_types=1);

namespace NovaPay\Payment\Setup\Patch\Data;

use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Sales\Model\Order as SalesOrder;
use Magento\Sales\Model\ResourceModel\Order\Status as StatusResource;
use NovaPay\Payment\Model\Order\StatusCode;
use Psr\Log\LoggerInterface;

/**
 * Removes the `pending_payment` state assignment of novapay_pending on existing installs.
 *
 * Earlier versions of {@see AddOrderStatuses} assigned novapay_pending to both `new` and
 * `pending_payment`, so Stores > Order Status listed it twice. The module only ever uses it with
 * state `new`; `pending_payment` keeps core's own `pending_payment` status.
 *
 * Calls the status resource model's `unassignState()` directly rather than
 * `Magento\Sales\Model\Order\Status::unassignState()`: the model refuses whenever ANY order uses
 * the status, in any state, and every install has `new` / novapay_pending orders, so it would
 * always throw here. The model's other guard (never unassign the last status of a state) is kept
 * below. The model's `sales_order_status_unassign` event only acts on state `new`, which this patch
 * does not touch.
 *
 * Idempotent: when the assignment is already gone, the delete matches no row.
 */
class UnassignPendingStatusFromPendingPaymentState implements DataPatchInterface
{
    /**
     * @param StatusResource $statusResource
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly StatusResource $statusResource,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @inheritdoc
     */
    public function apply(): self
    {
        if ($this->statusResource->checkIsStateLast(SalesOrder::STATE_PENDING_PAYMENT)) {
            $this->logger->warning(
                'NovaPay: novapay_pending was left assigned to pending_payment, because it is the only '
                . 'status of that state.',
                ['status' => StatusCode::PENDING, 'state' => SalesOrder::STATE_PENDING_PAYMENT]
            );

            return $this;
        }

        $this->statusResource->unassignState(StatusCode::PENDING, SalesOrder::STATE_PENDING_PAYMENT);

        return $this;
    }

    /**
     * @inheritdoc
     */
    public static function getDependencies(): array
    {
        return [AddFailedAndRefundedOrderStatuses::class];
    }

    /**
     * @inheritdoc
     */
    public function getAliases(): array
    {
        return [];
    }
}
