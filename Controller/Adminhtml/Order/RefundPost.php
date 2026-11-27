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
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use NovaPay\Payment\Controller\Adminhtml\AbstractOrderAction;
use NovaPay\Payment\Model\Refund\CreditmemoRefunder;
use NovaPay\Payment\Model\Refund\FormDataStore;
use NovaPay\Payment\Model\Refund\RefundAvailability;
use NovaPay\Payment\Model\Refund\RefundCalculator;
use NovaPay\Payment\Model\Order\GatewayFailureHistoryWriter;
use Psr\Log\LoggerInterface;

/**
 * Submits the NovaPay refund form: creates the standard online credit memo.
 *
 * The form posts `refund_mode`: `partial` refunds only the entered quantities and amounts
 * (`items[id][qty]`, `items[id][amount]`, `shipping_refund_amount`), `full` refunds all remaining
 * items and shipping and is only accepted when nothing was entered. A `partial` post without any
 * quantity or amount is rejected, so a form that lost its values never becomes a full refund. The
 * input is validated and mapped by {@see RefundCalculator::buildPlan()} (the server is authoritative:
 * per-line amount within the line's refundable amount, total within captured minus refunded).
 * The credit memo is built and refunded by {@see CreditmemoRefunder}, the same way the native credit
 * memo form does it, so the module's refund gateway command sends `POST /v1/void` and every
 * existing refund guard applies. Any error is shown to the admin, nothing is saved, and the entered
 * values are kept for the form ({@see FormDataStore}).
 */
class RefundPost extends AbstractOrderAction implements HttpPostActionInterface
{
    /**
     * Same permission as the native credit memo Save action.
     */
    public const ADMIN_RESOURCE = 'Magento_Sales::creditmemo';

    public const MODE_FULL = 'full';
    public const MODE_PARTIAL = 'partial';

    /**
     * Entered amounts this close to the whole remaining amount are a full refund.
     */
    private const FULL_AMOUNT_TOLERANCE = 0.005;

    /**
     * @param Context $context
     * @param OrderRepositoryInterface $orderRepository
     * @param RefundAvailability $refundAvailability
     * @param RefundCalculator $calculator
     * @param CreditmemoRefunder $refunder
     * @param LoggerInterface $logger
     * @param FormDataStore $formDataStore
     * @param GatewayFailureHistoryWriter $failureHistoryWriter
     */
    public function __construct(
        Context $context,
        OrderRepositoryInterface $orderRepository,
        RefundAvailability $refundAvailability,
        private readonly RefundCalculator $calculator,
        private readonly CreditmemoRefunder $refunder,
        private readonly LoggerInterface $logger,
        private readonly FormDataStore $formDataStore,
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
        if ($this->refundAvailability->getMode($order) !== RefundAvailability::MODE_REFUND) {
            $this->messageManager->addErrorMessage((string)__('A NovaPay refund is not available for this order.'));

            return $this->redirectToOrder($orderId);
        }

        try {
            $amount = $this->refund($order);
            $this->messageManager->addSuccessMessage((string)__(
                'The refund of %1 was sent to NovaPay and a credit memo was created. The order status '
                . 'will be updated after NovaPay confirms the refund.',
                $amount
            ));

            return $this->redirectToOrder($orderId);
        } catch (LocalizedException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        } catch (\Throwable $e) {
            $this->logger->critical('NovaPay refund button: the refund failed with an unexpected error.', [
                'event' => 'admin.refund_button_error',
                'order_increment_id' => $order->getIncrementId(),
                'exception' => $e->getMessage(),
            ]);
            $this->messageManager->addErrorMessage((string)__(
                'The NovaPay refund could not be completed. No credit memo was created. Check the '
                . 'NovaPay log for details.'
            ));
        } finally {
            // A rejected NovaPay call is also written to the order history (the operation's own
            // transaction was rolled back, so it is written here, afterwards).
            $this->failureHistoryWriter->writePending();
        }

        $this->formDataStore->save($orderId, $this->getPostedItems(), $this->getShippingInput());
        $redirect = $this->resultRedirectFactory->create();
        $redirect->setPath('novapay/order/refund', ['order_id' => $orderId]);

        return $redirect;
    }

    /**
     * Validate the form and create the online credit memo.
     *
     * @param Order $order
     * @return string the refunded amount, formatted in the order currency
     * @throws LocalizedException
     */
    private function refund(Order $order): string
    {
        $blockReason = $this->refundAvailability->getRefundBlockReason($order);
        if ($blockReason !== null) {
            throw new LocalizedException($blockReason);
        }

        $invoice = $this->calculator->getRefundInvoice($order);
        if ($invoice === null) {
            throw new LocalizedException(__('This order has no paid invoice that can be refunded.'));
        }

        $rows = $this->calculator->getRows($invoice);
        $shippingRow = $this->calculator->getShippingRow($invoice);

        $qtyInput = [];
        $amountInput = [];
        $backToStock = [];
        foreach ($this->getPostedItems() as $itemId => $itemData) {
            if (!is_array($itemData)) {
                continue;
            }
            if (array_key_exists('qty', $itemData)) {
                $qtyInput[$itemId] = $itemData['qty'];
            }
            if (array_key_exists('amount', $itemData)) {
                $amountInput[$itemId] = $itemData['amount'];
            }
            $backToStock[(int)$itemId] = !empty($itemData['back_to_stock']);
        }

        $fullAmount = $this->calculator->getFullRefundAmount($order);
        $plan = $this->calculator->buildPlan(
            $rows,
            $shippingRow,
            $qtyInput,
            $amountInput,
            $this->getShippingInput(),
            $fullAmount
        );
        $this->checkRefundMode($plan->isEmpty());

        if ($plan->isEmpty() || abs($plan->getTotal() - $fullAmount) <= self::FULL_AMOUNT_TOLERANCE) {
            // Nothing entered, or amounts adding up to the whole remaining amount: a full refund, built
            // like the native full credit memo so its total is exactly the remainder and the order closes.
            $creditmemo = $this->refunder->refund($invoice, array_keys($rows), [], $backToStock);
        } else {
            $partialReason = $this->refundAvailability->getPartialRefundBlockReason($order);
            if ($partialReason !== null) {
                throw new LocalizedException($partialReason);
            }
            $creditmemo = $this->refunder->refund(
                $invoice,
                array_keys($rows),
                $plan->getQtys(),
                $backToStock,
                $plan->getAdjustmentLines(),
                $plan->getTotal()
            );
        }

        return (string)$order->formatPriceTxt((float)$creditmemo->getGrandTotal());
    }

    /**
     * Check that the posted refund mode matches the entered quantities and amounts.
     *
     * @param bool $nothingEntered whether no quantity or amount was entered
     * @return void
     * @throws LocalizedException
     */
    private function checkRefundMode(bool $nothingEntered): void
    {
        $mode = (string)$this->getRequest()->getParam('refund_mode');
        if ($nothingEntered && $mode !== self::MODE_FULL) {
            throw new LocalizedException(__(
                'Enter the quantity or amount to refund for at least one item, or confirm a full refund.'
            ));
        }

        if (!$nothingEntered && $mode !== self::MODE_PARTIAL) {
            throw new LocalizedException(__(
                'The refund type does not match the entered quantities. Check the quantities and submit again.'
            ));
        }
    }

    /**
     * The `shipping_refund_amount` request parameter, '' when missing.
     *
     * @return string
     */
    private function getShippingInput(): string
    {
        $value = $this->getRequest()->getParam('shipping_refund_amount');

        return is_scalar($value) ? (string)$value : '';
    }

    /**
     * The `items` request parameter.
     *
     * @return array
     */
    private function getPostedItems(): array
    {
        $postedItems = $this->getRequest()->getParam('items');

        return is_array($postedItems) ? $postedItems : [];
    }
}
