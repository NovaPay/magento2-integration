<?php
/**
 * Copyright © 2026 NovaPay. All rights reserved.
 * Licensed under the MIT License. See LICENSE for details.
 */
declare(strict_types=1);

namespace NovaPay\Payment\Model\HoldConfirm;

use Magento\Backend\Model\Session as BackendSession;

/**
 * Keeps the amount the admin entered in the NovaPay hold confirmation form across the redirect
 * after an error, or the suggested amount after a partial amount that does not match whole items.
 *
 * {@see \NovaPay\Payment\Controller\Adminhtml\Order\ConfirmHoldPost} saves it,
 * {@see \NovaPay\Payment\Block\Adminhtml\Order\HoldConfirm\Form} takes it once when the form is
 * rendered again. Stored in the admin session under a key scoped to the order, separate from the
 * refund form's key.
 */
class FormDataStore
{
    private const KEY_PREFIX = 'novapay_hold_confirm_form_';

    /**
     * Longest amount string kept, anything longer is dropped.
     */
    private const MAX_AMOUNT_LENGTH = 32;

    /**
     * @param BackendSession $session
     */
    public function __construct(
        private readonly BackendSession $session
    ) {
    }

    /**
     * Save the amount of an order's hold confirmation form.
     *
     * @param int $orderId
     * @param mixed $amount the `amount` request parameter
     * @return void
     */
    public function save(int $orderId, mixed $amount): void
    {
        $value = is_scalar($amount) ? trim((string)$amount) : '';

        $this->session->setData(
            self::KEY_PREFIX . $orderId,
            strlen($value) <= self::MAX_AMOUNT_LENGTH ? $value : ''
        );
    }

    /**
     * Read and clear the saved amount of an order's hold confirmation form.
     *
     * @param int $orderId
     * @return string '' when nothing was saved
     */
    public function take(int $orderId): string
    {
        $value = $this->session->getData(self::KEY_PREFIX . $orderId, true);

        return is_string($value) ? $value : '';
    }
}
