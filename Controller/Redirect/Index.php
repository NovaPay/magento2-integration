<?php
/**
 * Copyright © 2026 NovaPay. All rights reserved.
 * Licensed under the MIT License. See LICENSE for details.
 */
declare(strict_types=1);

namespace NovaPay\Payment\Controller\Redirect;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Message\ManagerInterface;
use NovaPay\Payment\Gateway\Config;
use NovaPay\Payment\Gateway\Exception\InvalidKeyException;
use NovaPay\Payment\Gateway\Http\Exception\ApiException;
use NovaPay\Payment\Model\CheckoutOrchestrator;
use NovaPay\Payment\Model\Exception\PhoneValidationException;
use Psr\Log\LoggerInterface;

/**
 * Sends the customer from checkout to the NovaPay payment frame.
 *
 * Reached via `window.location.replace()` right after the order is placed (see
 * view/frontend/web/js/view/payment/method-renderer/novapay-method.js). The order already exists,
 * left `new` / `novapay_pending` by {@see \NovaPay\Payment\Gateway\Command\InitializeCommand}; this
 * controller never mutates order state itself — only {@see \NovaPay\Payment\Model\CheckoutOrchestrator}
 * calls NovaPay, and only PostbackProcessor/StatusMapper ever change order state/status.
 *
 * On any typed failure (missing phone, NovaPay 4xx/5xx/timeout, bad signing key) the quote is
 * restored and the customer is sent back to the cart with a friendly message; nothing here ever
 * renders a raw gateway error or stack trace to the shopper. The one exception is
 * {@see PhoneValidationException}: its message is the admin-configured, customer-facing phone
 * validation message, so it is shown as-is and the customer is sent back to checkout (not the
 * cart) to fix the address instead.
 *
 * @api
 */
class Index implements HttpGetActionInterface
{
    /**
     * @param CheckoutSession $checkoutSession
     * @param CheckoutOrchestrator $orchestrator
     * @param RedirectFactory $resultRedirectFactory
     * @param ManagerInterface $messageManager
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly CheckoutSession $checkoutSession,
        private readonly CheckoutOrchestrator $orchestrator,
        private readonly RedirectFactory $resultRedirectFactory,
        private readonly ManagerInterface $messageManager,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Build the NovaPay session + payment for the last placed order and redirect there.
     *
     * @return ResultInterface
     */
    public function execute(): ResultInterface
    {
        $order = $this->checkoutSession->getLastRealOrder();

        if (!$order->getId() || (string)$order->getPayment()?->getMethod() !== Config::CODE) {
            return $this->redirectToCart(
                __('We could not find your order. Please try placing it again.')
            );
        }

        try {
            $url = $this->orchestrator->buildRedirectUrl($order);
        } catch (PhoneValidationException $e) {
            $this->logger->error('NovaPay checkout redirect failed: invalid phone', [
                'event' => 'checkout.redirect_error',
                'order_increment_id' => $order->getIncrementId(),
                'exception' => $e->getMessage(),
            ]);

            return $this->redirectToCheckout(__($e->getMessage()));
        } catch (ApiException|InvalidKeyException|\InvalidArgumentException $e) {
            $this->logger->error('NovaPay checkout redirect failed', [
                'event' => 'checkout.redirect_error',
                'order_increment_id' => $order->getIncrementId(),
                'exception' => $e->getMessage(),
            ]);

            return $this->redirectToCart(
                __('We could not redirect you to NovaPay. Please try again or choose another payment method.')
            );
        }

        $resultRedirect = $this->resultRedirectFactory->create();
        $resultRedirect->setUrl($url);

        return $resultRedirect;
    }

    /**
     * Restore the quote and send the customer back to the cart with an error message. The order
     * itself is left untouched (still `novapay_pending`) — only the quote/session is restored so
     * checkout is usable again.
     *
     * @param \Magento\Framework\Phrase $message
     * @return ResultInterface
     */
    private function redirectToCart(\Magento\Framework\Phrase $message): ResultInterface
    {
        $this->checkoutSession->restoreQuote();
        $this->messageManager->addErrorMessage($message);

        $resultRedirect = $this->resultRedirectFactory->create();
        $resultRedirect->setPath('checkout/cart');

        return $resultRedirect;
    }

    /**
     * Restore the quote and send the customer back to checkout (not the cart) with an error
     * message — used for {@see PhoneValidationException}, where the fix is on the shipping/address
     * step the customer already filled in, not the cart. The order itself is left untouched (still
     * `novapay_pending`) — only the quote/session is restored so checkout is usable again.
     *
     * @param \Magento\Framework\Phrase $message
     * @return ResultInterface
     */
    private function redirectToCheckout(\Magento\Framework\Phrase $message): ResultInterface
    {
        $this->checkoutSession->restoreQuote();
        $this->messageManager->addErrorMessage($message);

        $resultRedirect = $this->resultRedirectFactory->create();
        $resultRedirect->setPath('checkout');

        return $resultRedirect;
    }
}
