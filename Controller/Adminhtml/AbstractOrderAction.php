<?php
/**
 * Copyright © 2026 NovaPay. All rights reserved.
 * Licensed under the MIT License. See LICENSE for details.
 */
declare(strict_types=1);

namespace NovaPay\Payment\Controller\Adminhtml;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use NovaPay\Payment\Model\Refund\RefundAvailability;

/**
 * Shared order loading and redirects of the NovaPay refund and hold confirmation button controllers.
 */
abstract class AbstractOrderAction extends Action
{
    /**
     * @param Context $context
     * @param OrderRepositoryInterface $orderRepository
     * @param RefundAvailability $refundAvailability
     */
    public function __construct(
        Context $context,
        protected readonly OrderRepositoryInterface $orderRepository,
        protected readonly RefundAvailability $refundAvailability
    ) {
        parent::__construct($context);
    }

    /**
     * The order from the `order_id` request parameter, or null when it does not exist.
     *
     * @return Order|null
     */
    protected function loadOrder(): ?Order
    {
        $orderId = (int)$this->getRequest()->getParam('order_id');
        if ($orderId <= 0) {
            return null;
        }

        try {
            $order = $this->orderRepository->get($orderId);
        } catch (NoSuchEntityException) {
            return null;
        }

        return $order instanceof Order ? $order : null;
    }

    /**
     * Result for a missing order or a disabled optional flow: the standard admin 404 page.
     *
     * @return ResultInterface
     */
    protected function notFound(): ResultInterface
    {
        /** @var \Magento\Backend\Model\View\Result\Forward $forward */
        $forward = $this->resultFactory->create(ResultFactory::TYPE_FORWARD);

        return $forward->forward('noroute');
    }

    /**
     * Redirect to the admin order view.
     *
     * @param int $orderId
     * @return ResultInterface
     */
    protected function redirectToOrder(int $orderId): ResultInterface
    {
        $redirect = $this->resultRedirectFactory->create();
        $redirect->setPath('sales/order/view', ['order_id' => $orderId]);

        return $redirect;
    }
}
