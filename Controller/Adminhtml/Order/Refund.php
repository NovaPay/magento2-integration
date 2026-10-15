<?php
/**
 * Copyright © 2026 NovaPay. All rights reserved.
 * Licensed under the MIT License. See LICENSE for details.
 */
declare(strict_types=1);

namespace NovaPay\Payment\Controller\Adminhtml\Order;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\View\Result\PageFactory;
use Magento\Sales\Api\OrderRepositoryInterface;
use NovaPay\Payment\Block\Adminhtml\Order\Refund\Form;
use NovaPay\Payment\Controller\Adminhtml\AbstractOrderAction;
use NovaPay\Payment\Model\Refund\RefundAvailability;

/**
 * Refund form of the optional NovaPay refund button (paid orders). Also shown read-only while a
 * released hold waits for the `voided` postback.
 *
 * Returns the admin 404 page when the option is off for the order's store or the order was not paid
 * with NovaPay, so the native behaviour is unchanged then.
 */
class Refund extends AbstractOrderAction implements HttpGetActionInterface
{
    /**
     * Same permission as the native Credit Memo button.
     */
    public const ADMIN_RESOURCE = 'Magento_Sales::creditmemo';

    /**
     * @param Context $context
     * @param OrderRepositoryInterface $orderRepository
     * @param RefundAvailability $refundAvailability
     * @param PageFactory $resultPageFactory
     */
    public function __construct(
        Context $context,
        OrderRepositoryInterface $orderRepository,
        RefundAvailability $refundAvailability,
        private readonly PageFactory $resultPageFactory
    ) {
        parent::__construct($context, $orderRepository, $refundAvailability);
    }

    /**
     * @inheritDoc
     */
    public function execute(): ResultInterface
    {
        $order = $this->loadOrder();
        if ($order === null || !$this->refundAvailability->isFlowActive($order)) {
            return $this->notFound();
        }

        $mode = $this->refundAvailability->getMode($order);
        if ($mode !== RefundAvailability::MODE_REFUND && $mode !== RefundAvailability::MODE_PENDING) {
            $this->messageManager->addErrorMessage((string)__('A NovaPay refund is not available for this order.'));

            return $this->redirectToOrder((int)$order->getEntityId());
        }

        /** @var \Magento\Backend\Model\View\Result\Page $page */
        $page = $this->resultPageFactory->create();
        $page->setActiveMenu('Magento_Sales::sales_order');
        $page->getConfig()->getTitle()->prepend((string)__('NovaPay Refund #%1', $order->getIncrementId()));

        $block = $page->getLayout()->getBlock('novapay_order_refund_form');
        if ($block instanceof Form) {
            $block->setOrder($order);
        }

        return $page;
    }
}
