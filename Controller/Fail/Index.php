<?php
/**
 * Copyright © 2026 NovaPay. All rights reserved.
 * Licensed under the MIT License. See LICENSE for details.
 */
declare(strict_types=1);

namespace NovaPay\Payment\Controller\Fail;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Message\ManagerInterface;
use Magento\Sales\Model\Order;
use NovaPay\Payment\Gateway\Config;

/**
 * Default `fail_url` the customer's browser is redirected to by the NovaPay payment frame after an
 * abandoned/failed payment. Overridable via the "Fail URL" admin field, in which case this
 * controller is never reached.
 *
 * Restores the quote and shows a message only when the checkout session's last real order actually
 * is a not-yet-paid NovaPay order (exists, method is novapay, state new/pending_payment) — a bare
 * GET to this URL must not spoof an error message or restore an unrelated/resolved quote. Never
 * touches order state/status; that is the postback pipeline's job only.
 *
 * @api
 */
class Index implements HttpGetActionInterface
{
    /**
     * @param CheckoutSession $checkoutSession
     * @param ManagerInterface $messageManager
     * @param RedirectFactory $resultRedirectFactory
     */
    public function __construct(
        private readonly CheckoutSession $checkoutSession,
        private readonly ManagerInterface $messageManager,
        private readonly RedirectFactory $resultRedirectFactory
    ) {
    }

    /**
     * Restore the quote and show a message only for a genuinely restorable NovaPay order.
     *
     * @return ResultInterface
     */
    public function execute(): ResultInterface
    {
        $order = $this->checkoutSession->getLastRealOrder();

        if ($this->isAwaitingNovaPayPayment($order)) {
            $this->checkoutSession->restoreQuote();
            $this->messageManager->addErrorMessage(
                __('Your NovaPay payment was not completed. Please try again or choose another payment method.')
            );
        }

        $resultRedirect = $this->resultRedirectFactory->create();
        $resultRedirect->setPath('checkout/cart');

        return $resultRedirect;
    }

    /**
     * Whether `$order` exists, was paid with NovaPay, and is still awaiting payment.
     *
     * @param Order $order
     * @return bool
     */
    private function isAwaitingNovaPayPayment(Order $order): bool
    {
        if (!$order->getId()) {
            return false;
        }

        if ((string)$order->getPayment()?->getMethod() !== Config::CODE) {
            return false;
        }

        return in_array($order->getState(), [Order::STATE_NEW, Order::STATE_PENDING_PAYMENT], true);
    }
}
