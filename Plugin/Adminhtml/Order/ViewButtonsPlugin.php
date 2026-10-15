<?php
/**
 * Copyright © 2026 NovaPay. All rights reserved.
 * Licensed under the MIT License. See LICENSE for details.
 */
declare(strict_types=1);

namespace NovaPay\Payment\Plugin\Adminhtml\Order;

use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Serialize\Serializer\Json as JsonSerializer;
use Magento\Framework\View\LayoutInterface;
use Magento\Sales\Block\Adminhtml\Order\View;
use Magento\Sales\Model\Order;
use NovaPay\Payment\Model\Refund\RefundAvailability;

/**
 * Order view buttons of the optional NovaPay refund flow.
 *
 * Only when the "NovaPay refund button in order view" option is on and the order was paid with
 * NovaPay:
 *
 * - removes the native "Credit Memo" button (it would open the offline credit memo form) and the
 *   payment "Void" button (on a hold it is the same operation as the NovaPay button);
 * - adds the "NovaPay Refund" button per {@see RefundAvailability::getMode()}: on a hold it asks for
 *   confirmation and posts to novapay/order/cancelHold, on a paid order it opens the refund form. The
 *   button stays until the `voided` postback: after a refund or a hold release it opens the form
 *   read-only with a notice.
 *
 * The native "Cancel" button is kept: on a hold it does the same as the NovaPay button.
 * Orders paid with other methods, and every order while the option is off, are not touched.
 */
class ViewButtonsPlugin
{
    public const BUTTON_ID = 'novapay_refund';

    /**
     * @param RefundAvailability $availability
     * @param AuthorizationInterface $authorization
     * @param JsonSerializer $json
     */
    public function __construct(
        private readonly RefundAvailability $availability,
        private readonly AuthorizationInterface $authorization,
        private readonly JsonSerializer $json
    ) {
    }

    /**
     * Adjust the button bar before the container pushes its buttons to the toolbar.
     *
     * @param View $subject
     * @param LayoutInterface $layout
     * @return null
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function beforeSetLayout(View $subject, LayoutInterface $layout)
    {
        $order = $subject->getOrder();
        if (!$order instanceof Order || !$this->availability->isFlowActive($order)) {
            return null;
        }

        $subject->removeButton('order_creditmemo');
        $subject->removeButton('void_payment');

        $mode = $this->availability->getMode($order);
        if (($mode === RefundAvailability::MODE_REFUND || $mode === RefundAvailability::MODE_PENDING)
            && $this->authorization->isAllowed('Magento_Sales::creditmemo')
        ) {
            $subject->addButton(self::BUTTON_ID, [
                'label' => __('NovaPay Refund'),
                'class' => 'credit-memo novapay-refund',
                'id' => 'novapay-refund-button',
                'onclick' => sprintf(
                    "setLocation('%s')",
                    $subject->escapeJs($subject->getUrl('novapay/order/refund'))
                ),
            ]);
        } elseif ($mode === RefundAvailability::MODE_CANCEL_HOLD
            && $this->authorization->isAllowed('Magento_Sales::cancel')
        ) {
            $subject->addButton(self::BUTTON_ID, [
                'label' => __('NovaPay Refund'),
                'class' => 'credit-memo novapay-refund',
                'id' => 'novapay-refund-button',
                'data_attribute' => [
                    'mage-init' => $this->json->serialize([
                        'NovaPay_Payment/js/order/cancel-hold-button' => [
                            'url' => $subject->getUrl('novapay/order/cancelHold'),
                            'message' => (string)__(
                                'Release the NovaPay hold for the full amount? The funds will be unblocked '
                                . 'and the order canceled. Partial hold cancellation is not supported.'
                            ),
                        ],
                    ]),
                ],
            ]);
        }

        return null;
    }
}
