<?php
/**
 * Copyright © 2026 NovaPay. All rights reserved.
 * Licensed under the MIT License. See LICENSE for details.
 */
declare(strict_types=1);

namespace NovaPay\Payment\Model\Refund;

use Magento\Backend\Model\Session as BackendSession;

/**
 * Keeps the values the admin entered in the NovaPay refund form across the redirect after an error.
 *
 * {@see \NovaPay\Payment\Controller\Adminhtml\Order\RefundPost} saves the submitted quantities and
 * "Return to Stock" flags when the refund fails; {@see \NovaPay\Payment\Block\Adminhtml\Order\Refund\Form}
 * takes them once when the form is rendered again, so a second click does not turn a partial refund
 * into a full one. The values are stored in the admin session under a key scoped to the order.
 */
class FormDataStore
{
    private const KEY_PREFIX = 'novapay_refund_form_';

    /**
     * Longest quantity string kept, anything longer is dropped.
     */
    private const MAX_QTY_LENGTH = 32;

    /**
     * @param BackendSession $session
     */
    public function __construct(
        private readonly BackendSession $session
    ) {
    }

    /**
     * Save the submitted items of an order's refund form.
     *
     * @param int $orderId
     * @param array $postedItems `items` request parameter: [item id => ['qty' => ..., 'back_to_stock' => ...]]
     * @return void
     */
    public function save(int $orderId, array $postedItems): void
    {
        $items = [];
        foreach ($postedItems as $itemId => $itemData) {
            if (!is_array($itemData) || (int)$itemId <= 0) {
                continue;
            }
            $qty = isset($itemData['qty']) && is_scalar($itemData['qty']) ? trim((string)$itemData['qty']) : '';
            $items[(int)$itemId] = [
                'qty' => strlen($qty) <= self::MAX_QTY_LENGTH ? $qty : '',
                'back_to_stock' => !empty($itemData['back_to_stock']),
            ];
        }

        $this->session->setData(self::KEY_PREFIX . $orderId, $items);
    }

    /**
     * Read and clear the saved items of an order's refund form.
     *
     * @param int $orderId
     * @return array<int, array{qty: string, back_to_stock: bool}> empty when nothing was saved
     */
    public function take(int $orderId): array
    {
        $items = $this->session->getData(self::KEY_PREFIX . $orderId, true);

        return is_array($items) ? $items : [];
    }
}
