<?php
/**
 * Copyright © 2026 NovaPay. All rights reserved.
 * Licensed under the MIT License. See LICENSE for details.
 */
declare(strict_types=1);

namespace NovaPay\Payment\Controller\Success;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Controller\ResultInterface;

/**
 * Default `success_url` the customer's browser is redirected to by the NovaPay payment frame after
 * a completed payment. Overridable via the "Success URL" admin field, in which case this
 * controller is never reached.
 *
 * Never trust this browser-side redirect for order state: it never changes anything on the order —
 * only the postback pipeline does. Sends the customer to Magento's native checkout success page,
 * but only when the checkout session actually has a last real order to show.
 *
 * @api
 */
class Index implements HttpGetActionInterface
{
    /**
     * @param CheckoutSession $checkoutSession
     * @param RedirectFactory $resultRedirectFactory
     */
    public function __construct(
        private readonly CheckoutSession $checkoutSession,
        private readonly RedirectFactory $resultRedirectFactory
    ) {
    }

    /**
     * Send the customer to the native success page for a real order, else back to the cart.
     *
     * @return ResultInterface
     */
    public function execute(): ResultInterface
    {
        $resultRedirect = $this->resultRedirectFactory->create();

        if (!$this->checkoutSession->getLastRealOrder()->getId()) {
            $resultRedirect->setPath('checkout/cart');

            return $resultRedirect;
        }

        $resultRedirect->setPath('checkout/onepage/success');

        return $resultRedirect;
    }
}
