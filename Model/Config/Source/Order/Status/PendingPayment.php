<?php
/**
 * Copyright © 2026 NovaPay. All rights reserved.
 * Licensed under the MIT License. See LICENSE for details.
 */
declare(strict_types=1);

namespace NovaPay\Payment\Model\Config\Source\Order\Status;

use Magento\Sales\Model\Config\Source\Order\Status;
use Magento\Sales\Model\Order;

/**
 * Admin config source listing only order statuses assigned to the `pending_payment` state.
 *
 * Used by the "NovaPay Order Status Mapping" Processing and Failed rows so the dropdowns offer
 * exactly the statuses {@see \NovaPay\Payment\Model\StatusMapper} accepts for those rows. Core ships per-state sources
 * only for new/processing; this mirrors core's Status\Processing.
 */
class PendingPayment extends Status
{
    /**
     * @var string[]
     */
    protected $_stateStatuses = [Order::STATE_PENDING_PAYMENT];
}
