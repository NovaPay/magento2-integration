<?php
/**
 * Copyright © 2026 NovaPay. All rights reserved.
 * Licensed under the MIT License. See LICENSE for details.
 */
declare(strict_types=1);

namespace NovaPay\Payment\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use Magento\Payment\Model\Method\AbstractMethod;

/**
 * "Payment Type" admin config source: direct (one-step) vs hold (two-step).
 *
 * The option value is stored directly as the Magento payment_action value
 * consumed by the checkout order-place flow (Gateway\Config::getPaymentAction()).
 */
class PaymentAction implements OptionSourceInterface
{
    /**
     * @inheritdoc
     */
    public function toOptionArray(): array
    {
        return [
            [
                'value' => AbstractMethod::ACTION_AUTHORIZE_CAPTURE,
                'label' => __('Direct (capture immediately)'),
            ],
            [
                'value' => AbstractMethod::ACTION_AUTHORIZE,
                'label' => __('Hold (two-step, confirm capture later)'),
            ],
        ];
    }
}
