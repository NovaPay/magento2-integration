<?php
/**
 * Copyright © 2026 NovaPay. All rights reserved.
 * Licensed under the MIT License. See LICENSE for details.
 */
declare(strict_types=1);

namespace NovaPay\Payment\Block\Adminhtml\Order\View;

use Magento\Backend\Block\Template\Context;
use Magento\Framework\Registry;
use Magento\Framework\Serialize\Serializer\Json as JsonSerializer;
use Magento\Sales\Block\Adminhtml\Order\AbstractOrder;
use Magento\Sales\Helper\Admin as AdminHelper;
use Magento\Sales\Model\Order\Item as OrderItem;
use NovaPay\Payment\Gateway\Config;
use NovaPay\Payment\Gateway\Http\PanMasker;

/**
 * Read-only admin order-view info block (session_id, status history). Reads only data already
 * persisted by this module (`sales_order_payment.additional_information`); no new table, no new
 * admin route/ACL — gated by the standard `Magento_Sales::actions_view` permission.
 *
 * Rendered into the core `order_additional_info` container (shared, not method-specific); only
 * shows anything when {@see isNovaPayOrder()} is true.
 *
 * {@see getRefundedItems()} is Magento's own native per-order-item ledger (always accurate).
 * {@see getRefundedOperations()} is the NovaPay-confirmed refund ledger, which tracks one NovaPay
 * operation id per order — a session-level total, not a per-item breakdown. Each entry's
 * `refund_amount` is NovaPay's own CUMULATIVE refunded amount for that operation as of the last
 * processed event, not a per-event delta — see
 * {@see \NovaPay\Payment\Model\OrderStatusApplier::applyPartialVoid()}'s docblock.
 *
 * @api
 */
class NovaPayInfo extends AbstractOrder
{
    private const INFO_SESSION_ID = 'novapay_session_id';
    private const INFO_STATUS = 'novapay_status';
    private const INFO_LAST_EVENT = 'novapay_last_event';
    private const INFO_PAYTYPE = 'novapay_paytype';
    private const INFO_RRN = 'novapay_rrn';
    private const INFO_APPROVAL = 'novapay_approval';
    private const INFO_CARD_PAN = 'novapay_card_pan';
    private const INFO_CARD_BANK = 'novapay_card_bank';
    private const INFO_CARD_COUNTRY = 'novapay_card_country';
    private const INFO_CARD_TYPE = 'novapay_card_type';
    private const INFO_LAST_AT = 'novapay_last_postback_at';
    private const INFO_HASHES = 'novapay_postback_hashes';
    private const INFO_REFUNDED_OPERATIONS = 'novapay_refunded_operations';

    /**
     * @param Context $context
     * @param Registry $registry
     * @param AdminHelper $adminHelper
     * @param JsonSerializer $json
     * @param PanMasker $panMasker
     * @param array $data
     */
    public function __construct(
        Context $context,
        Registry $registry,
        AdminHelper $adminHelper,
        private readonly JsonSerializer $json,
        private readonly PanMasker $panMasker,
        array $data = []
    ) {
        parent::__construct($context, $registry, $adminHelper, $data);
    }

    /**
     * Whether the current order was paid with this module.
     *
     * The only case the template renders anything, since `order_additional_info` is a shared, not
     * method-specific, container.
     *
     * @return bool
     */
    public function isNovaPayOrder(): bool
    {
        $payment = $this->getOrder()->getPayment();

        return $payment !== null && $payment->getMethod() === Config::CODE;
    }

    /**
     * The NovaPay session id.
     *
     * @return string|null
     */
    public function getSessionId(): ?string
    {
        return $this->getPaymentInfo(self::INFO_SESSION_ID);
    }

    /**
     * The last session-level NovaPay status applied.
     *
     * @return string|null
     */
    public function getNovaPayStatus(): ?string
    {
        return $this->getPaymentInfo(self::INFO_STATUS);
    }

    /**
     * The raw event status of the most recently processed postback.
     *
     * @return string|null
     */
    public function getLastEvent(): ?string
    {
        return $this->getPaymentInfo(self::INFO_LAST_EVENT);
    }

    /**
     * The payment method used at NovaPay (card, apple_pay, google_pay, wallet).
     *
     * @return string|null
     */
    public function getPaytype(): ?string
    {
        return $this->getPaymentInfo(self::INFO_PAYTYPE);
    }

    /**
     * The card transaction's Reference Retrieval Number.
     *
     * @return string|null
     */
    public function getRrn(): ?string
    {
        return $this->getPaymentInfo(self::INFO_RRN);
    }

    /**
     * The card scheme approval code.
     *
     * @return string|null
     */
    public function getApproval(): ?string
    {
        return $this->getPaymentInfo(self::INFO_APPROVAL);
    }

    /**
     * The masked PAN, already masked by NovaPay before this module ever sees it.
     *
     * E.g. `522712******0426`. Re-applies {@see PanMasker::mask()} as defense-in-depth; a no-op on
     * an already-masked value.
     *
     * @return string|null
     */
    public function getMaskedPan(): ?string
    {
        $pan = $this->getPaymentInfo(self::INFO_CARD_PAN);

        return $pan !== null ? $this->panMasker->mask($pan) : null;
    }

    /**
     * The issuing bank of the card used, if known.
     *
     * @return string|null
     */
    public function getCardBank(): ?string
    {
        return $this->getPaymentInfo(self::INFO_CARD_BANK);
    }

    /**
     * The issuing country of the card used, if known.
     *
     * @return string|null
     */
    public function getCardCountry(): ?string
    {
        return $this->getPaymentInfo(self::INFO_CARD_COUNTRY);
    }

    /**
     * The card type/brand, if known.
     *
     * @return string|null
     */
    public function getCardType(): ?string
    {
        return $this->getPaymentInfo(self::INFO_CARD_TYPE);
    }

    /**
     * The timestamp of the most recently processed postback.
     *
     * @return string|null
     */
    public function getLastPostbackAt(): ?string
    {
        return $this->getPaymentInfo(self::INFO_LAST_AT);
    }

    /**
     * The number of distinct postback payloads processed so far.
     *
     * Backed by {@see \NovaPay\Payment\Model\OrderStatusApplier}'s bounded
     * `novapay_postback_hashes` list, capped at 20 — this count saturates at 20 for a
     * heavily-retried session by design.
     *
     * @return int
     */
    public function getPostbackCount(): int
    {
        return count($this->decodeJsonArray($this->getPaymentInfo(self::INFO_HASHES)));
    }

    /**
     * NovaPay-confirmed partial-refund operations recorded so far (see class docblock).
     *
     * Each `{id: string, refund_amount: float, recorded_at: string}` — `refund_amount` is the
     * CUMULATIVE amount refunded for that operation as of `recorded_at`, not a per-event delta.
     *
     * @return array<int, array{id: string, refund_amount: float, recorded_at: string}>
     */
    public function getRefundedOperations(): array
    {
        return $this->decodeJsonArray($this->getPaymentInfo(self::INFO_REFUNDED_OPERATIONS));
    }

    /**
     * Native Magento per-order-item refunded qty/amount (see class docblock).
     *
     * One row per visible order item that has been refunded at least once.
     *
     * @return array<int, array{name: string, qty_refunded: float, amount_refunded: float}>
     */
    public function getRefundedItems(): array
    {
        $rows = [];
        foreach ($this->getOrder()->getAllVisibleItems() as $item) {
            /** @var OrderItem $item */
            $qtyRefunded = (float)$item->getQtyRefunded();
            if ($qtyRefunded <= 0.0) {
                continue;
            }

            $rows[] = [
                'name' => (string)$item->getName(),
                'qty_refunded' => $qtyRefunded,
                'amount_refunded' => (float)$item->getAmountRefunded(),
            ];
        }

        return $rows;
    }

    /**
     * Read a single `additional_information` key off the current order's payment.
     *
     * @param string $key
     * @return string|null
     */
    private function getPaymentInfo(string $key): ?string
    {
        $payment = $this->getOrder()->getPayment();
        if ($payment === null) {
            return null;
        }

        $value = $payment->getAdditionalInformation($key);

        return $value !== null && $value !== '' ? (string)$value : null;
    }

    /**
     * Decode a previously JSON-serialized additional_information value, never throwing.
     *
     * @param string|null $stored
     * @return array
     */
    private function decodeJsonArray(?string $stored): array
    {
        if ($stored === null || $stored === '') {
            return [];
        }

        try {
            $decoded = $this->json->unserialize($stored);
        } catch (\InvalidArgumentException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }
}
