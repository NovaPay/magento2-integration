<?php
/**
 * Copyright © 2026 NovaPay. All rights reserved.
 * Licensed under the MIT License. See LICENSE for details.
 */
declare(strict_types=1);

namespace NovaPay\Payment\Block\Adminhtml\Order\Refund;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use Magento\CatalogInventory\Api\StockConfigurationInterface;
use Magento\Framework\Locale\Format as LocaleFormat;
use Magento\Framework\Phrase;
use Magento\Framework\Serialize\Serializer\Json as JsonSerializer;
use Magento\Sales\Model\Order;
use NovaPay\Payment\Model\Refund\FormDataStore;
use NovaPay\Payment\Model\Refund\RefundAvailability;
use NovaPay\Payment\Model\Refund\RefundCalculator;

/**
 * Refund form of the optional NovaPay refund button (spec 8.2.3 - 8.2.6).
 *
 * Per paid invoice line: name, SKU, paid quantity, refunded quantity, "quantity to refund" input,
 * editable "amount to refund" (prefilled with quantity x unit amount) with the amount already
 * refunded for the line (spec 8.4), and a shipping row with an amount input; below: the total, the
 * amount refunded through NovaPay without a line (a refund made in the NovaPay cabinet), the
 * partial refund banner and the
 * "Refund via NovaPay" button. When a refund or a partial refund is not possible, the reason is shown
 * up front and the inputs are disabled. After a failed submit the entered values come back from
 * {@see FormDataStore}.
 *
 * The order is set by {@see \NovaPay\Payment\Controller\Adminhtml\Order\Refund}.
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
     * @var array|null
     */
    private ?array $rows = null;

    /**
     * @var array|null
     */
    private ?array $preservedItems = null;

    /**
     * @var array|null
     */
    private ?array $shippingRow = null;

    /**
     * @var bool
     */
    private bool $shippingRowLoaded = false;

    /**
     * @param Context $context
     * @param RefundAvailability $availability
     * @param RefundCalculator $calculator
     * @param StockConfigurationInterface $stockConfiguration
     * @param LocaleFormat $localeFormat
     * @param JsonSerializer $json
     * @param FormDataStore $formDataStore
     * @param array $data
     */
    public function __construct(
        Context $context,
        private readonly RefundAvailability $availability,
        private readonly RefundCalculator $calculator,
        private readonly StockConfigurationInterface $stockConfiguration,
        private readonly LocaleFormat $localeFormat,
        private readonly JsonSerializer $json,
        private readonly FormDataStore $formDataStore,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * Set the order being refunded.
     *
     * @param Order $order
     * @return $this
     */
    public function setOrder(Order $order): self
    {
        $this->order = $order;
        $this->rows = null;
        $this->preservedItems = null;
        $this->shippingRow = null;
        $this->shippingRowLoaded = false;

        return $this;
    }

    /**
     * The order being refunded.
     *
     * @return Order|null
     */
    public function getOrder(): ?Order
    {
        return $this->order;
    }

    /**
     * Refund form rows (see {@see RefundCalculator::getRows()}).
     *
     * @return array
     */
    public function getRows(): array
    {
        if ($this->rows === null) {
            $invoice = $this->order !== null ? $this->calculator->getRefundInvoice($this->order) : null;
            $this->rows = $invoice !== null ? $this->calculator->getRows($invoice) : [];
        }

        return $this->rows;
    }

    /**
     * The shipping row (see {@see RefundCalculator::getShippingRow()}), or null.
     *
     * @return array|null
     */
    public function getShippingRow(): ?array
    {
        if (!$this->shippingRowLoaded) {
            $this->shippingRowLoaded = true;
            $invoice = $this->order !== null ? $this->calculator->getRefundInvoice($this->order) : null;
            $this->shippingRow = $invoice !== null ? $this->calculator->getShippingRow($invoice) : null;
        }

        return $this->shippingRow;
    }

    /**
     * Amount refunded through NovaPay without a line (a refund made in the NovaPay cabinet).
     *
     * @return float
     */
    public function getUnattributedRefunded(): float
    {
        return $this->order !== null ? $this->calculator->getUnattributedRefunded($this->order) : 0.0;
    }

    /**
     * Why no refund is possible, or null. Also null-safe when the form has no rows.
     *
     * @return Phrase|null
     */
    public function getRefundBlockReason(): ?Phrase
    {
        if ($this->order === null) {
            return __('A NovaPay refund is not available for this order.');
        }

        $reason = $this->availability->getRefundBlockReason($this->order);
        if ($reason === null && $this->getRows() === []) {
            $reason = __('This order has no paid invoice that can be refunded.');
        }

        return $reason;
    }

    /**
     * Notice of a refund or hold release that waits for the NovaPay postback, or null.
     *
     * @return Phrase|null
     */
    public function getPendingNotice(): ?Phrase
    {
        return $this->order !== null ? $this->availability->getPendingNotice($this->order) : null;
    }

    /**
     * The quantity the admin entered for an item before the last failed submit, '' when none.
     *
     * @param int $itemId
     * @return string
     */
    public function getPreservedQty(int $itemId): string
    {
        $items = $this->getPreservedItems();

        return isset($items[$itemId]['qty']) ? (string)$items[$itemId]['qty'] : '';
    }

    /**
     * The amount entered for a line before the last failed submit, '' when none.
     *
     * The line is an order item id or "shipping".
     *
     * @param int|string $lineId
     * @return string
     */
    public function getPreservedAmount(int|string $lineId): string
    {
        $items = $this->getPreservedItems();

        return isset($items[$lineId]['amount']) ? (string)$items[$lineId]['amount'] : '';
    }

    /**
     * The "Return to Stock" state of an item: as submitted before the last failed submit, else the default.
     *
     * @param int $itemId
     * @param bool $default
     * @return bool
     */
    public function isBackToStockChecked(int $itemId, bool $default): bool
    {
        $items = $this->getPreservedItems();

        return isset($items[$itemId]['back_to_stock']) ? !empty($items[$itemId]['back_to_stock']) : $default;
    }

    /**
     * Why only a full refund is possible, or null.
     *
     * @return Phrase|null
     */
    public function getPartialRefundBlockReason(): ?Phrase
    {
        return $this->order !== null ? $this->availability->getPartialRefundBlockReason($this->order) : null;
    }

    /**
     * Whether the "Return to Stock" checkboxes are shown (same setting as the native credit memo form).
     *
     * @return bool
     */
    public function canReturnToStock(): bool
    {
        return (bool)$this->stockConfiguration->canSubtractQty();
    }

    /**
     * Default state of the "Return to Stock" checkboxes.
     *
     * @return bool
     */
    public function isAutoReturnEnabled(): bool
    {
        return (bool)$this->stockConfiguration->isAutoReturnEnabled();
    }

    /**
     * An amount formatted in the order currency, plain text.
     *
     * @param float $amount
     * @return string
     */
    public function formatAmount(float $amount): string
    {
        return $this->order !== null ? (string)$this->order->formatPriceTxt($amount) : (string)$amount;
    }

    /**
     * A quantity without trailing zeros.
     *
     * @param float $qty
     * @return string
     */
    public function formatQty(float $qty): string
    {
        return $this->calculator->formatQty($qty);
    }

    /**
     * Amount of a full refund (everything paid and not yet refunded, shipping included).
     *
     * @return float
     */
    public function getFullRefundAmount(): float
    {
        return $this->order !== null ? $this->calculator->getFullRefundAmount($this->order) : 0.0;
    }

    /**
     * Form action.
     *
     * @return string
     */
    public function getSubmitUrl(): string
    {
        return $this->getUrl('novapay/order/refundPost', ['order_id' => $this->getOrderId()]);
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
     * Configuration of the NovaPay_Payment/js/order/refund-form component.
     *
     * @return string JSON
     */
    public function getJsConfig(): string
    {
        $units = [];
        foreach ($this->getRows() as $itemId => $row) {
            $units[(string)$itemId] = round((float)$row['unit_amount'], 4);
        }

        $currencyCode = $this->order !== null ? (string)$this->order->getOrderCurrencyCode() : null;

        return (string)$this->json->serialize([
            'unitAmounts' => $units,
            'fullAmount' => $this->getFullRefundAmount(),
            'maxTotal' => $this->getFullRefundAmount(),
            'totalTooHigh' => (string)__('The total amount to refund cannot be more than %1.'),
            'errorQty' => (string)__('Enter a quantity from 0 to %1.'),
            'errorAmount' => (string)__('Enter an amount from 0 to %1.'),
            'errorZeroAmount' => (string)__('Enter an amount above 0, or clear the quantity.'),
            'priceFormat' => $this->localeFormat->getPriceFormat(null, $currencyCode ?: null),
            'confirmFull' => (string)__('Refund the full order amount (%1)?'),
            'confirmPartial' => (string)__('Refund %1 via NovaPay?'),
        ]);
    }

    /**
     * Values saved by the last failed submit, read (and cleared) once per order.
     *
     * @return array
     */
    private function getPreservedItems(): array
    {
        if ($this->preservedItems === null) {
            $orderId = $this->getOrderId();
            $this->preservedItems = $orderId > 0 ? $this->formDataStore->take($orderId) : [];
        }

        return $this->preservedItems;
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
