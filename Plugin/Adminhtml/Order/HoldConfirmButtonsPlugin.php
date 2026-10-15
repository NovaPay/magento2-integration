<?php
/**
 * Copyright © 2026 NovaPay. All rights reserved.
 * Licensed under the MIT License. See LICENSE for details.
 */
declare(strict_types=1);

namespace NovaPay\Payment\Plugin\Adminhtml\Order;

use Magento\Framework\AuthorizationInterface;
use Magento\Framework\View\LayoutInterface;
use Magento\Sales\Block\Adminhtml\Order\View;
use Magento\Sales\Model\Order;
use NovaPay\Payment\Model\HoldConfirm\HoldConfirmAvailability;

/**
 * Order view buttons of the optional NovaPay hold confirmation flow (spec 7).
 *
 * Only when the "NovaPay hold confirmation button in order view" option is on, the order was paid
 * with NovaPay and {@see HoldConfirmAvailability::getMode()} returns a mode (a NovaPay hold waiting
 * for confirmation or for its postback):
 *
 * - removes the native "Invoice" button (the hold is confirmed through the NovaPay button instead);
 * - adds the "Confirm NovaPay Hold" button, if the admin may invoice: it opens the confirmation form,
 *   or the same form read-only while the confirmation waits for the postback.
 *
 * Works next to {@see ViewButtonsPlugin} (refund option): each plugin touches only its own buttons.
 * Orders paid with other methods, every order while the option is off, and NovaPay orders without a
 * hold to confirm (e.g. paid in one step) keep the native buttons, including "Invoice".
 */
class HoldConfirmButtonsPlugin
{
    public const BUTTON_ID = 'novapay_hold_confirm';

    /**
     * @param HoldConfirmAvailability $availability
     * @param AuthorizationInterface $authorization
     */
    public function __construct(
        private readonly HoldConfirmAvailability $availability,
        private readonly AuthorizationInterface $authorization
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
        if (!$order instanceof Order || $this->availability->getMode($order) === null) {
            // No NovaPay hold to confirm (getMode() also checks the option and the method): native buttons stay.
            return null;
        }

        $subject->removeButton('order_invoice');

        if ($this->authorization->isAllowed('Magento_Sales::invoice')) {
            $subject->addButton(self::BUTTON_ID, [
                'label' => __('Confirm NovaPay Hold'),
                'class' => 'invoice novapay-hold-confirm',
                'id' => 'novapay-hold-confirm-button',
                'onclick' => sprintf(
                    "setLocation('%s')",
                    $subject->escapeJs($subject->getUrl('novapay/order/confirmHold'))
                ),
            ]);
        }

        return null;
    }
}
