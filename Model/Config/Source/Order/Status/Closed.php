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
 * Admin config source listing only order statuses assigned to the `closed` state.
 *
 * Used by the "NovaPay Order Status Mapping" Voided row so the dropdown offers exactly the statuses
 * {@see \NovaPay\Payment\Model\StatusMapper} accepts for that row. Core ships per-state sources
 * only for new/processing; this mirrors core's Status\Processing.
 */
class Closed extends Status
{
    /**
     * @var string[]
     */
    protected $_stateStatuses = [Order::STATE_CLOSED];
}
