<?php
/**
 * Copyright © 2026 NovaPay. All rights reserved.
 * Licensed under the MIT License. See LICENSE for details.
 */
declare(strict_types=1);

namespace NovaPay\Payment\Block\Adminhtml\Order\Creditmemo;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\Registry;
use Magento\Sales\Model\Order\Creditmemo;
use NovaPay\Payment\Gateway\Config;

/**
 * Credit memo banner: partial refund only returns funds at NovaPay if the online Refund button
 * (this module's {@see \NovaPay\Payment\Gateway\Command\RefundCommand}) is used; an offline
 * refund never does.
 *
 * Rendered on the new-credit-memo form for every order (the layout container is not
 * method-specific); {@see shouldShow()} makes the template a no-op for non-NovaPay orders.
 *
 * @api
 */
class Banner extends Template
{
    /**
     * @param Context $context
     * @param Registry $registry
     * @param array $data
     */
    public function __construct(
        Context $context,
        private readonly Registry $registry,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * Whether the order this credit memo is being created for was paid with NovaPay.
     *
     * @return bool
     */
    public function shouldShow(): bool
    {
        $creditmemo = $this->registry->registry('current_creditmemo');
        if (!$creditmemo instanceof Creditmemo) {
            return false;
        }

        $order = $creditmemo->getOrder();
        if ($order === null) {
            return false;
        }

        $payment = $order->getPayment();

        return $payment !== null && $payment->getMethod() === Config::CODE;
    }
}
