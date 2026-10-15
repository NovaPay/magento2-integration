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
use NovaPay\Payment\Block\Adminhtml\Order\HoldConfirm\Form;
use NovaPay\Payment\Controller\Adminhtml\AbstractOrderAction;
use NovaPay\Payment\Model\HoldConfirm\HoldConfirmAvailability;
use NovaPay\Payment\Model\Refund\RefundAvailability;

/**
 * Form of the optional "Confirm NovaPay Hold" button (spec 7.3). Also shown read-only while the
 * confirmation waits for the NovaPay postback.
 *
 * Returns the admin 404 page when the option is off for the order's store or the order was not paid
 * with NovaPay, so the native behaviour is unchanged then.
 */
class ConfirmHold extends AbstractOrderAction implements HttpGetActionInterface
{
    /**
     * Same permission as the native Invoice button.
     */
    public const ADMIN_RESOURCE = 'Magento_Sales::invoice';

    /**
     * @param Context $context
     * @param OrderRepositoryInterface $orderRepository
     * @param RefundAvailability $refundAvailability
     * @param HoldConfirmAvailability $holdAvailability
     * @param PageFactory $resultPageFactory
     */
    public function __construct(
        Context $context,
        OrderRepositoryInterface $orderRepository,
        RefundAvailability $refundAvailability,
        private readonly HoldConfirmAvailability $holdAvailability,
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
        if ($order === null || !$this->holdAvailability->isFlowActive($order)) {
            return $this->notFound();
        }

        if ($this->holdAvailability->getMode($order) === null) {
            $this->messageManager->addErrorMessage(
                (string)__('A NovaPay hold confirmation is not available for this order.')
            );

            return $this->redirectToOrder((int)$order->getEntityId());
        }

        /** @var \Magento\Backend\Model\View\Result\Page $page */
        $page = $this->resultPageFactory->create();
        $page->setActiveMenu('Magento_Sales::sales_order');
        $page->getConfig()->getTitle()->prepend((string)__('Confirm NovaPay Hold #%1', $order->getIncrementId()));

        $block = $page->getLayout()->getBlock('novapay_order_hold_confirm_form');
        if ($block instanceof Form) {
            $block->setOrder($order);
        }

        return $page;
    }
}
