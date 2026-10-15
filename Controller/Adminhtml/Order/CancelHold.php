<?php
/**
 * Copyright © 2026 NovaPay. All rights reserved.
 * Licensed under the MIT License. See LICENSE for details.
 */
declare(strict_types=1);

namespace NovaPay\Payment\Controller\Adminhtml\Order;

use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\OrderManagementInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use NovaPay\Payment\Controller\Adminhtml\AbstractOrderAction;
use NovaPay\Payment\Model\Refund\RefundAvailability;
use NovaPay\Payment\Model\Order\GatewayFailureHistoryWriter;
use Psr\Log\LoggerInterface;

/**
 * NovaPay refund button on a hold: cancels the order through the core order management service.
 *
 * This is the same operation as the native Cancel button: the payment's cancel command sends
 * `POST /v1/void` without operations[], which releases the whole hold (a partial hold cancel is not
 * supported). When NovaPay returns an error the order is not canceled and the error is shown.
 */
class CancelHold extends AbstractOrderAction implements HttpPostActionInterface
{
    /**
     * Same permission as the native Cancel button.
     */
    public const ADMIN_RESOURCE = 'Magento_Sales::cancel';

    /**
     * @param Context $context
     * @param OrderRepositoryInterface $orderRepository
     * @param RefundAvailability $refundAvailability
     * @param OrderManagementInterface $orderManagement
     * @param LoggerInterface $logger
     * @param GatewayFailureHistoryWriter $failureHistoryWriter
     */
    public function __construct(
        Context $context,
        OrderRepositoryInterface $orderRepository,
        RefundAvailability $refundAvailability,
        private readonly OrderManagementInterface $orderManagement,
        private readonly LoggerInterface $logger,
        private readonly GatewayFailureHistoryWriter $failureHistoryWriter
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

        $orderId = (int)$order->getEntityId();
        if ($this->refundAvailability->getMode($order) !== RefundAvailability::MODE_CANCEL_HOLD) {
            $this->messageManager->addErrorMessage((string)__('This order has no NovaPay hold to release.'));

            return $this->redirectToOrder($orderId);
        }

        try {
            if (!$this->orderManagement->cancel($orderId)) {
                throw new LocalizedException(__('This order has no NovaPay hold to release.'));
            }
            $this->messageManager->addSuccessMessage((string)__(
                'The hold was released and the funds were unblocked. The status will be updated after '
                . 'NovaPay confirms it.'
            ));
        } catch (LocalizedException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        } catch (\Throwable $e) {
            $this->logger->critical('NovaPay refund button: releasing the hold failed with an unexpected error.', [
                'event' => 'admin.cancel_hold_button_error',
                'order_increment_id' => $order->getIncrementId(),
                'exception' => $e->getMessage(),
            ]);
            $this->messageManager->addErrorMessage((string)__(
                'The NovaPay hold could not be released. The order was not canceled. Check the NovaPay '
                . 'log for details.'
            ));
        } finally {
            // A rejected NovaPay call is also written to the order history (the operation's own
            // transaction was rolled back, so it is written here, afterwards).
            $this->failureHistoryWriter->writePending();
        }

        return $this->redirectToOrder($orderId);
    }
}
