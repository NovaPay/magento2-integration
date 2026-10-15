<?php
/**
 * Copyright © 2026 NovaPay. All rights reserved.
 * Licensed under the MIT License. See LICENSE for details.
 */
declare(strict_types=1);

namespace NovaPay\Payment\Plugin\Adminhtml\Order;

use Magento\Sales\Block\Adminhtml\Order\Creditmemo\Create\Items;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Creditmemo;
use NovaPay\Payment\Model\Refund\RefundAvailability;

/**
 * Removes "Refund Offline" from the new credit memo form for NovaPay orders when the optional NovaPay
 * refund button is on.
 *
 * Core adds it as the `submit_offline` child next to the online "Refund" button, or as the
 * `submit_button` child itself when no online refund is possible (credit memo opened from the order
 * instead of the paid invoice). Both offline variants are removed; the online "Refund" button stays.
 * Other payment methods are not touched.
 */
class CreditmemoItemsButtonsPlugin
{
    /**
     * @param RefundAvailability $availability
     */
    public function __construct(
        private readonly RefundAvailability $availability
    ) {
    }

    /**
     * Unset the offline refund buttons after core created them in `_prepareLayout()`.
     *
     * @param Items $subject
     * @param Items $result
     * @return Items
     */
    public function afterSetLayout(Items $subject, $result)
    {
        $creditmemo = $subject->getCreditmemo();
        if (!$creditmemo instanceof Creditmemo) {
            return $result;
        }

        $order = $creditmemo->getOrder();
        if (!$order instanceof Order || !$this->availability->isFlowActive($order)) {
            return $result;
        }

        $subject->unsetChild('submit_offline');

        $invoice = $creditmemo->getInvoice();
        $onlinePossible = $creditmemo->canRefund() && $invoice && $invoice->getTransactionId();
        if (!$onlinePossible) {
            $subject->unsetChild('submit_button');
        }

        return $result;
    }
}
