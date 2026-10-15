<?php
/**
 * Copyright © 2026 NovaPay. All rights reserved.
 * Licensed under the MIT License. See LICENSE for details.
 */
declare(strict_types=1);

namespace NovaPay\Payment\Plugin\Adminhtml\Order;

use Magento\Framework\App\ActionInterface;
use NovaPay\Payment\Model\Order\GatewayFailureHistoryWriter;

/**
 * After the native admin credit memo Save, invoice Save, VoidPayment and Cancel actions, writes any
 * failed NovaPay gateway call of the request to the order history (see
 * {@see GatewayFailureHistoryWriter}). A no-op when no NovaPay call failed.
 */
class GatewayFailureHistoryPlugin
{
    /**
     * @param GatewayFailureHistoryWriter $writer
     */
    public function __construct(
        private readonly GatewayFailureHistoryWriter $writer
    ) {
    }

    /**
     * Write the pending failure notes once the action (and its rolled-back transaction) is done.
     *
     * @param ActionInterface $subject
     * @param mixed $result
     * @return mixed
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterExecute(ActionInterface $subject, mixed $result): mixed
    {
        $this->writer->writePending();

        return $result;
    }
}
