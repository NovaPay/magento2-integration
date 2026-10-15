<?php
/**
 * Copyright © 2026 NovaPay. All rights reserved.
 * Licensed under the MIT License. See LICENSE for details.
 */
declare(strict_types=1);

namespace NovaPay\Payment\Model\Refund;

use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\CreditmemoManagementInterface;
use Magento\Sales\Api\Data\CreditmemoInterface;
use Magento\Sales\Controller\Adminhtml\Order\CreditmemoLoader;
use Magento\Sales\Model\Order\Creditmemo;
use Magento\Sales\Model\Order\Invoice;

/**
 * Creates the standard online credit memo for the NovaPay refund form.
 *
 * It repeats what the native "Refund" button of the credit memo form does
 * ({@see \Magento\Sales\Controller\Adminhtml\Order\Creditmemo\Save}): the core
 * {@see CreditmemoLoader} builds the credit memo from the paid invoice and the posted item data
 * (quantities, "Return to Stock" flags, shipping and adjustments), the parent quantities are
 * aligned with their children, and {@see CreditmemoManagementInterface::refund()} refunds it online.
 * That runs the module's refund gateway command (`POST /v1/void`) inside the core database
 * transaction, so a failed NovaPay call leaves no credit memo behind.
 *
 * Full refund (no quantities entered): every remaining item plus the remaining shipping, exactly as
 * the native form prefills it. Partial refund: only the entered quantities, shipping 0.
 * Adjustments are always 0 because NovaPay refunds do not support them.
 */
class CreditmemoRefunder
{
    /**
     * @param CreditmemoLoader $creditmemoLoader
     * @param CreditmemoManagementInterface $creditmemoManagement
     */
    public function __construct(
        private readonly CreditmemoLoader $creditmemoLoader,
        private readonly CreditmemoManagementInterface $creditmemoManagement
    ) {
    }

    /**
     * Build and refund the credit memo online.
     *
     * @param Invoice $invoice the paid invoice to refund against
     * @param int[] $itemIds order item ids of all refund form rows
     * @param array<int,float> $qtys order item id => quantity; empty for a full refund
     * @param array<int,bool> $backToStock order item id => return the refunded quantity to stock
     * @return CreditmemoInterface
     * @throws LocalizedException when the credit memo cannot be built or the refund fails
     */
    public function refund(Invoice $invoice, array $itemIds, array $qtys, array $backToStock): CreditmemoInterface
    {
        $isFullRefund = $qtys === [];

        $items = [];
        foreach ($itemIds as $itemId) {
            $itemData = [];
            if (!$isFullRefund) {
                $itemData['qty'] = (float)($qtys[$itemId] ?? 0.0);
            }
            if (!empty($backToStock[$itemId])) {
                $itemData['back_to_stock'] = '1';
            }
            $items[(int)$itemId] = $itemData;
        }

        $data = [
            'items' => $items,
            'do_offline' => '0',
            'adjustment_positive' => '0',
            'adjustment_negative' => '0',
        ];
        if (!$isFullRefund) {
            $data['shipping_amount'] = '0';
        }

        $this->creditmemoLoader->setCreditmemoId(null);
        $this->creditmemoLoader->setOrderId($invoice->getOrderId());
        $this->creditmemoLoader->setInvoiceId($invoice->getId());
        $this->creditmemoLoader->setCreditmemo($data);
        $creditmemo = $this->creditmemoLoader->load();
        if (!$creditmemo instanceof Creditmemo) {
            throw new LocalizedException(__('We can\'t create credit memo for the order.'));
        }

        $this->adjustParentQuantities($creditmemo);

        if (!$creditmemo->isValidGrandTotal()) {
            throw new LocalizedException(__('The credit memo\'s total must be positive.'));
        }

        $creditmemo->setOfflineRequested(false);
        $creditmemo->getOrder()->setCustomerNoteNotify(0);

        return $this->creditmemoManagement->refund($creditmemo, false);
    }

    /**
     * Parent item quantity = sum of its children, as the native credit memo Save controller does.
     *
     * @param Creditmemo $creditmemo
     * @return void
     */
    private function adjustParentQuantities(Creditmemo $creditmemo): void
    {
        $items = $creditmemo->getAllItems();
        $parentQuantities = [];
        foreach ($items as $item) {
            $parentId = $item->getOrderItem()->getParentItemId();
            if ($parentId) {
                $parentQuantities[$parentId] = ($parentQuantities[$parentId] ?? 0) + $item->getQty();
            }
        }

        foreach ($parentQuantities as $parentId => $quantity) {
            foreach ($items as $item) {
                if ($item->getOrderItemId() == $parentId) {
                    $item->setQty($quantity);
                }
            }
        }
    }
}
