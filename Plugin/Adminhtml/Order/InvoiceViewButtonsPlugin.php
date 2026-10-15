<?php
/**
 * Copyright © 2026 NovaPay. All rights reserved.
 * Licensed under the MIT License. See LICENSE for details.
 */
declare(strict_types=1);

namespace NovaPay\Payment\Plugin\Adminhtml\Order;

use Magento\Framework\View\LayoutInterface;
use Magento\Sales\Block\Adminhtml\Order\Invoice\View;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Invoice;
use NovaPay\Payment\Model\Refund\RefundAvailability;

/**
 * Removes the invoice view "Credit Memo" button for NovaPay orders when the optional NovaPay refund
 * button is on; refunds are then made from the order view. Other payment methods are not touched.
 */
class InvoiceViewButtonsPlugin
{
    /**
     * @param RefundAvailability $availability
     */
    public function __construct(
        private readonly RefundAvailability $availability
    ) {
    }

    /**
     * Remove the button before the container pushes its buttons to the toolbar.
     *
     * @param View $subject
     * @param LayoutInterface $layout
     * @return null
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function beforeSetLayout(View $subject, LayoutInterface $layout)
    {
        $invoice = $subject->getInvoice();
        $order = $invoice instanceof Invoice ? $invoice->getOrder() : null;
        if ($order instanceof Order && $this->availability->isFlowActive($order)) {
            $subject->removeButton('credit-memo');
        }

        return null;
    }
}
