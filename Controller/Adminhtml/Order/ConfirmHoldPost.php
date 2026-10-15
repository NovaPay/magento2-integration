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
use NovaPay\Payment\Model\HoldConfirm\AmountValidator;
use NovaPay\Payment\Model\HoldConfirm\CaptureQtyResolver;
use NovaPay\Payment\Model\HoldConfirm\FormDataStore;
use NovaPay\Payment\Model\HoldConfirm\HoldConfirmAvailability;
use NovaPay\Payment\Model\HoldConfirm\HoldInvoiceCreator;
use NovaPay\Payment\Model\HoldConfirm\InvoiceLinesFormatter;
use NovaPay\Payment\Model\Refund\RefundAvailability;
use NovaPay\Payment\Model\Order\GatewayFailureHistoryWriter;
use Psr\Log\LoggerInterface;

/**
 * Submits the NovaPay hold confirmation form: creates the standard online-capture invoice.
 *
 * The entered amount is validated ({@see AmountValidator}) and mapped to invoice quantities
 * ({@see CaptureQtyResolver}): the full hold amount invoices every item, a smaller amount invoices
 * the whole units it covers. When a partial amount does not match whole units, nothing is invoiced:
 * the form comes back with the nearest lower amount that does (and names the nearest higher one, when
 * there is one), for the admin to confirm. The invoice
 * is created by {@see HoldInvoiceCreator} like the core invoice Save action, so
 * {@see \NovaPay\Payment\Gateway\Command\CaptureCommand} sends `POST /v1/complete-hold`. Any error is
 * shown, nothing is saved, and the entered amount is kept for the form ({@see FormDataStore}).
 */
class ConfirmHoldPost extends AbstractOrderAction implements HttpPostActionInterface
{
    /**
     * Same permission as the native invoice Save action.
     */
    public const ADMIN_RESOURCE = 'Magento_Sales::invoice';

    /**
     * @param Context $context
     * @param OrderRepositoryInterface $orderRepository
     * @param RefundAvailability $refundAvailability
     * @param HoldConfirmAvailability $holdAvailability
     * @param AmountValidator $amountValidator
     * @param CaptureQtyResolver $qtyResolver
     * @param HoldInvoiceCreator $invoiceCreator
     * @param FormDataStore $formDataStore
     * @param LoggerInterface $logger
     * @param GatewayFailureHistoryWriter $failureHistoryWriter
     * @param InvoiceLinesFormatter $linesFormatter
     */
    public function __construct(
        Context $context,
        OrderRepositoryInterface $orderRepository,
        RefundAvailability $refundAvailability,
        private readonly HoldConfirmAvailability $holdAvailability,
        private readonly AmountValidator $amountValidator,
        private readonly CaptureQtyResolver $qtyResolver,
        private readonly HoldInvoiceCreator $invoiceCreator,
        private readonly FormDataStore $formDataStore,
        private readonly LoggerInterface $logger,
        private readonly GatewayFailureHistoryWriter $failureHistoryWriter,
        private readonly InvoiceLinesFormatter $linesFormatter
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

        $orderId = (int)$order->getEntityId();
        if ($this->holdAvailability->getMode($order) !== HoldConfirmAvailability::MODE_CONFIRM) {
            $this->messageManager->addErrorMessage(
                (string)__('A NovaPay hold confirmation is not available for this order.')
            );

            return $this->redirectToOrder($orderId);
        }

        $rawAmount = $this->getRequest()->getParam('amount');
        try {
            $confirmed = $this->confirm($order, $rawAmount);
            if ($confirmed !== null) {
                $this->messageManager->addSuccessMessage((string)__(
                    'The hold confirmation of %1 was sent to NovaPay and an invoice was created. The order '
                    . 'status will be updated after NovaPay confirms it.',
                    $confirmed
                ));

                return $this->redirectToOrder($orderId);
            }
        } catch (LocalizedException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
            $this->formDataStore->save($orderId, $rawAmount);
        } catch (\Throwable $e) {
            $this->logger->critical('NovaPay hold confirmation button: the confirmation failed unexpectedly.', [
                'event' => 'admin.hold_confirm_button_error',
                'order_increment_id' => $order->getIncrementId(),
                'exception' => $e->getMessage(),
            ]);
            $this->messageManager->addErrorMessage((string)__(
                'The NovaPay hold could not be confirmed. No invoice was created. Check the NovaPay log '
                . 'for details.'
            ));
            $this->formDataStore->save($orderId, $rawAmount);
        } finally {
            // A rejected NovaPay call is also written to the order history (the operation's own
            // transaction was rolled back, so it is written here, afterwards).
            $this->failureHistoryWriter->writePending();
        }

        $redirect = $this->resultRedirectFactory->create();
        $redirect->setPath('novapay/order/confirmHold', ['order_id' => $orderId]);

        return $redirect;
    }

    /**
     * Validate the amount and create the invoice.
     *
     * @param Order $order
     * @param mixed $rawAmount
     * @return string|null the confirmed amount formatted in the base currency, or null when the amount
     *     does not match whole units and the nearest lower amount was proposed instead
     * @throws LocalizedException
     */
    private function confirm(Order $order, mixed $rawAmount): ?string
    {
        $amount = $this->amountValidator->validate(
            $rawAmount,
            $this->holdAvailability->getHoldAmount($order),
            $this->holdAvailability->isPartialAllowed($order)
        );

        $resolution = $this->qtyResolver->resolve($order, $amount);
        if (!$resolution['full'] && abs($resolution['total'] - $amount) > CaptureQtyResolver::AMOUNT_TOLERANCE) {
            $suggested = number_format($resolution['total'], 2, '.', '');
            $this->formDataStore->save((int)$order->getEntityId(), $suggested);
            $next = $resolution['next_total'] ?? null;
            if ($next !== null) {
                $this->messageManager->addWarningMessage((string)__(
                    'The hold confirmation is invoiced per item, so %1 cannot be confirmed exactly. The nearest '
                    . 'lower amount that covers whole items together with the shipping is %2 (%3), the nearest '
                    . 'higher one is %4 (%5). The amount was changed to %2: check it, or enter %4, and submit '
                    . 'again.',
                    number_format($amount, 2, '.', ''),
                    $suggested,
                    $this->linesFormatter->format($resolution['lines']),
                    number_format((float)$next, 2, '.', ''),
                    $this->linesFormatter->format($resolution['next_lines'] ?? [])
                ));

                return null;
            }
            $this->messageManager->addWarningMessage((string)__(
                'The hold confirmation is invoiced per item, so %1 cannot be confirmed exactly. The nearest '
                . 'lower amount that covers whole items together with the shipping is %2 (%3). The amount '
                . 'was changed to %2: check it and submit again.',
                number_format($amount, 2, '.', ''),
                $suggested,
                $this->linesFormatter->format($resolution['lines'])
            ));

            return null;
        }

        $invoice = $this->invoiceCreator->create($order, $resolution['qtys'], $resolution['total']);

        return (string)$order->getBaseCurrency()->formatTxt((float)$invoice->getBaseGrandTotal());
    }
}
