<?php
/**
 * Copyright © 2026 NovaPay. All rights reserved.
 * Licensed under the MIT License. See LICENSE for details.
 */
declare(strict_types=1);

namespace NovaPay\Payment\Setup;

use Magento\Sales\Model\Order\Status;
use Magento\Sales\Model\Order\StatusFactory;

/**
 * Shared by the module's order-status data patches: creates an order status if it does not exist
 * yet and assigns it to the given order state(s).
 *
 * Idempotent: the status is loaded by primary key (its code) before insert, and state assignment
 * goes through Status::assignState(), which itself uses INSERT ... ON DUPLICATE KEY UPDATE. An
 * existing status keeps its (possibly merchant-edited) label.
 */
class OrderStatusInstaller
{
    /**
     * @param StatusFactory $statusFactory
     */
    public function __construct(
        private readonly StatusFactory $statusFactory
    ) {
    }

    /**
     * Create (if missing) an order status and assign it to the given state(s).
     *
     * Each assignment is non-default and visible on the storefront.
     *
     * @param string $code
     * @param string $label
     * @param string[] $states
     * @return void
     */
    public function install(string $code, string $label, array $states): void
    {
        /** @var Status $status */
        $status = $this->statusFactory->create();
        $status->load($code);
        if (!$status->getId()) {
            $status->setData(['status' => $code, 'label' => $label]);
            $status->save();
        }

        foreach ($states as $state) {
            $status->assignState($state, false, true);
        }
    }
}
