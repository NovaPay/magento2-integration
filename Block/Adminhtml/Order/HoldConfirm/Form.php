<?php
/**
 * Copyright © 2026 NovaPay. All rights reserved.
 * Licensed under the MIT License. See LICENSE for details.
 */
declare(strict_types=1);

namespace NovaPay\Payment\Block\Adminhtml\Order\HoldConfirm;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\Locale\Format as LocaleFormat;
use Magento\Framework\Phrase;
use Magento\Framework\Serialize\Serializer\Json as JsonSerializer;
use Magento\Sales\Model\Order;
use NovaPay\Payment\Model\HoldConfirm\FormDataStore;
use NovaPay\Payment\Model\HoldConfirm\HoldConfirmAvailability;

/**
 * Form of the optional "Confirm NovaPay Hold" button (spec 7.3).
 *
 * Shows the hold amount, the amount to confirm (prefilled with the full hold amount, base currency),
 * the partial confirmation note, the Recipient Identifier status and the "Confirm Hold" button. While
 * the confirmation waits for the NovaPay postback the form is read-only with a notice. After a failed
 * submit the entered amount comes back from {@see FormDataStore}.
 *
 * The order is set by {@see \NovaPay\Payment\Controller\Adminhtml\Order\ConfirmHold}.
 *
 * @api
 */
class Form extends Template
{
    /**
     * @var Order|null
     */
    private ?Order $order = null;

    /**
     * @var string|null
     */
    private ?string $preservedAmount = null;

    /**
     * @param Context $context
     * @param HoldConfirmAvailability $availability
     * @param LocaleFormat $localeFormat
     * @param JsonSerializer $json
     * @param FormDataStore $formDataStore
     * @param array $data
     */
    public function __construct(
        Context $context,
        private readonly HoldConfirmAvailability $availability,
        private readonly LocaleFormat $localeFormat,
        private readonly JsonSerializer $json,
        private readonly FormDataStore $formDataStore,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * Set the order whose hold is confirmed.
     *
     * @param Order $order
     * @return $this
     */
    public function setOrder(Order $order): self
    {
        $this->order = $order;
        $this->preservedAmount = null;

        return $this;
    }

    /**
     * The order whose hold is confirmed.
     *
     * @return Order|null
     */
    public function getOrder(): ?Order
    {
        return $this->order;
    }

    /**
     * Notice of a confirmation that waits for the NovaPay postback, or null.
     *
     * @return Phrase|null
     */
    public function getPendingNotice(): ?Phrase
    {
        return $this->order !== null ? $this->availability->getPendingNotice($this->order) : null;
    }

    /**
     * Whether the form can be submitted.
     *
     * @return bool
     */
    public function canSubmit(): bool
    {
        return $this->order !== null
            && $this->availability->getMode($this->order) === HoldConfirmAvailability::MODE_CONFIRM;
    }

    /**
     * The held amount (base currency).
     *
     * @return float
     */
    public function getHoldAmount(): float
    {
        return $this->order !== null ? $this->availability->getHoldAmount($this->order) : 0.0;
    }

    /**
     * Value of the amount input: the amount kept from the last failed submit, else the full hold amount.
     *
     * @return string
     */
    public function getAmountValue(): string
    {
        if ($this->preservedAmount === null) {
            $orderId = $this->order !== null ? (int)$this->order->getEntityId() : 0;
            $this->preservedAmount = $orderId > 0 ? $this->formDataStore->take($orderId) : '';
        }

        if ($this->preservedAmount !== '' && $this->isPartialAllowed()) {
            return $this->preservedAmount;
        }

        return number_format($this->getHoldAmount(), 2, '.', '');
    }

    /**
     * Whether a partial confirmation is possible (a Recipient Identifier is configured).
     *
     * @return bool
     */
    public function isPartialAllowed(): bool
    {
        return $this->order !== null && $this->availability->isPartialAllowed($this->order);
    }

    /**
     * The configured Recipient Identifier, '' when not set.
     *
     * @return string
     */
    public function getRecipientIdentifier(): string
    {
        return $this->order !== null ? $this->availability->getRecipientIdentifier($this->order) : '';
    }

    /**
     * An amount formatted in the base currency, plain text.
     *
     * @param float $amount
     * @return string
     */
    public function formatAmount(float $amount): string
    {
        return $this->order !== null
            ? (string)$this->order->getBaseCurrency()->formatTxt($amount)
            : number_format($amount, 2, '.', '');
    }

    /**
     * Form action.
     *
     * @return string
     */
    public function getSubmitUrl(): string
    {
        return $this->getUrl('novapay/order/confirmHoldPost', ['order_id' => $this->getOrderId()]);
    }

    /**
     * Back to the order view.
     *
     * @return string
     */
    public function getBackUrl(): string
    {
        return $this->getUrl('sales/order/view', ['order_id' => $this->getOrderId()]);
    }

    /**
     * Configuration of the NovaPay_Payment/js/order/hold-confirm-form component.
     *
     * @return string JSON
     */
    public function getJsConfig(): string
    {
        $currencyCode = $this->order !== null ? (string)$this->order->getBaseCurrencyCode() : null;

        return (string)$this->json->serialize([
            'holdAmount' => $this->getHoldAmount(),
            'partialAllowed' => $this->isPartialAllowed(),
            'priceFormat' => $this->localeFormat->getPriceFormat(null, $currencyCode ?: null),
            'messages' => [
                'invalid' => (string)__(
                    'Enter the amount to confirm as a number with at most 2 decimals, for example 250.50.'
                ),
                'zero' => (string)__('The amount to confirm must be greater than zero.'),
                'over' => (string)__(
                    'The amount to confirm cannot exceed the hold amount of %1.',
                    number_format($this->getHoldAmount(), 2, '.', '')
                ),
                'noRecipient' => (string)__(
                    'A partial hold confirmation requires the Recipient Identifier (ЄДРПОУ/ІПН) in the NovaPay '
                    . 'payment settings. Confirm the full hold amount or configure the identifier.'
                ),
                'confirmFull' => (string)__('Confirm the full NovaPay hold of %1?'),
                'confirmPartial' => (string)__(
                    'Confirm %1 of the NovaPay hold? The rest of the hold is returned to the payer.'
                ),
            ],
        ]);
    }

    /**
     * The current order id.
     *
     * @return int
     */
    private function getOrderId(): int
    {
        return $this->order !== null ? (int)$this->order->getEntityId() : 0;
    }
}
