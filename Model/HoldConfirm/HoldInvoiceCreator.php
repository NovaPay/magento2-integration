<?php
/**
 * Copyright © 2026 NovaPay. All rights reserved.
 * Licensed under the MIT License. See LICENSE for details.
 */
declare(strict_types=1);

namespace NovaPay\Payment\Model\HoldConfirm;

use Magento\Framework\DB\TransactionFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\Service\InvoiceService;

/**
 * Creates the standard online-capture invoice of the NovaPay hold confirmation button, the same way
 * core's `Magento\Sales\Controller\Adminhtml\Order\Invoice\Save` does it: prepare the invoice with
 * {@see InvoiceService}, request an online capture, register it and save invoice and order in one
 * DB transaction.
 *
 * Registering the invoice runs the payment's capture, so
 * {@see \NovaPay\Payment\Gateway\Command\CaptureCommand} sends `POST /v1/complete-hold` (full, or
 * partial with operations[] when the invoice total is below the hold amount) and keeps the invoice
 * pending until the postback. When the command throws, nothing is saved.
 */
class HoldInvoiceCreator
{
    /**
     * Amounts have 2 decimals: closer than this means equal (float noise only).
     */
    private const AMOUNT_TOLERANCE = 0.001;

    /**
     * @param InvoiceService $invoiceService
     * @param TransactionFactory $transactionFactory
     */
    public function __construct(
        private readonly InvoiceService $invoiceService,
        private readonly TransactionFactory $transactionFactory
    ) {
    }

    /**
     * Create, capture online and save the invoice.
     *
     * @param Order $order
     * @param array $qtys order item id => qty; empty invoices every item
     * @param float $expectedTotal the base grand total the admin confirmed
     * @return Invoice
     * @throws LocalizedException
     */
    public function create(Order $order, array $qtys, float $expectedTotal): Invoice
    {
        if (!$order->canInvoice()) {
            throw new LocalizedException(__('The order does not allow an invoice to be created.'));
        }

        /** @var Invoice $invoice */
        $invoice = $this->invoiceService->prepareInvoice($order, $qtys);
        if (!$invoice->getTotalQty()) {
            throw new LocalizedException(
                __("The invoice can't be created without products. Add products and try again.")
            );
        }

        if (abs((float)$invoice->getBaseGrandTotal() - $expectedTotal) > self::AMOUNT_TOLERANCE) {
            throw new LocalizedException(__(
                'The invoice total %1 does not match the amount to confirm %2. The order may have '
                . 'changed, open the form again.',
                number_format((float)$invoice->getBaseGrandTotal(), 2, '.', ''),
                number_format($expectedTotal, 2, '.', '')
            ));
        }

        // Without an online capture core would register the invoice as pending and never call NovaPay.
        if (!$invoice->canCapture()) {
            throw new LocalizedException(__('The NovaPay hold of this order cannot be captured online.'));
        }

        $invoice->setRequestedCaptureCase(Invoice::CAPTURE_ONLINE);
        $invoice->register();
        $order->setIsInProcess(true);

        $this->transactionFactory->create()
            ->addObject($invoice)
            ->addObject($order)
            ->save();

        return $invoice;
    }
}
