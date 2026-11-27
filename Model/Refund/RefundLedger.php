<?php
/**
 * Copyright © 2026 NovaPay. All rights reserved.
 * Licensed under the MIT License. See LICENSE for details.
 */
declare(strict_types=1);

namespace NovaPay\Payment\Model\Refund;

use Magento\Framework\Serialize\Serializer\Json as JsonSerializer;
use Magento\Payment\Model\InfoInterface;

/**
 * What was refunded per order line, kept in the payment's additional information under
 * {@see INFO_KEY} (spec 8.4: the refunded amount is shown next to every refunded line).
 *
 * Stored shape (JSON):
 *
 * ```
 * {
 *   "lines": {"123": 50.00, "shipping": 10.00},
 *   "unattributed": 15.00,
 *   "auto_creditmemos": [{"key": "9871113:15.00", "amount": 15.00, "creditmemo_id": "42"}]
 * }
 * ```
 *
 * - `lines`: order item id (or {@see SHIPPING}) => amount refunded for it, in the order currency.
 *   Written by {@see CreditmemoRefunder} for refunds made with the NovaPay refund form, both the
 *   quantity part and the amount part ("Adjustment Refund") of the credit memo.
 * - `unattributed`: refunded through NovaPay without a line (a refund made in the NovaPay cabinet,
 *   turned into an automatic offline credit memo by {@see \NovaPay\Payment\Model\OrderStatusApplier}).
 * - `auto_creditmemos`: idempotency record of those automatic credit memos, see {@see hasAutoCreditmemo()}.
 *
 * The methods only change the payment object; saving is the caller's job.
 */
class RefundLedger
{
    public const INFO_KEY = 'novapay_refund_attribution';
    public const SHIPPING = 'shipping';

    /**
     * @param JsonSerializer $json
     */
    public function __construct(
        private readonly JsonSerializer $json
    ) {
    }

    /**
     * Refunded amount per line: order item id (as string) or {@see SHIPPING} => amount.
     *
     * @param InfoInterface|null $payment
     * @return array<string,float>
     */
    public function getLines(?InfoInterface $payment): array
    {
        return $this->read($payment)['lines'];
    }

    /**
     * Amount refunded through NovaPay without a line.
     *
     * @param InfoInterface|null $payment
     * @return float
     */
    public function getUnattributed(?InfoInterface $payment): float
    {
        return $this->read($payment)['unattributed'];
    }

    /**
     * Add refunded amounts to their lines.
     *
     * @param InfoInterface $payment
     * @param array<int|string,float> $amounts order item id or {@see SHIPPING} => amount
     * @return void
     */
    public function addLines(InfoInterface $payment, array $amounts): void
    {
        $ledger = $this->read($payment);
        foreach ($amounts as $key => $amount) {
            $amount = round((float)$amount, 2);
            if ($amount <= 0.0) {
                continue;
            }
            $key = (string)$key;
            $ledger['lines'][$key] = round(($ledger['lines'][$key] ?? 0.0) + $amount, 2);
        }

        $this->write($payment, $ledger);
    }

    /**
     * Whether an automatic credit memo was already created for this key.
     *
     * @param InfoInterface $payment
     * @param string $key
     * @return bool
     */
    public function hasAutoCreditmemo(InfoInterface $payment, string $key): bool
    {
        foreach ($this->read($payment)['auto_creditmemos'] as $entry) {
            if (($entry['key'] ?? null) === $key) {
                return true;
            }
        }

        return false;
    }

    /**
     * Record an automatic credit memo for a refund made outside Magento.
     *
     * @param InfoInterface $payment
     * @param string $key idempotency key, see {@see hasAutoCreditmemo()}
     * @param float $amount refunded amount in the order currency
     * @param string $creditmemoId
     * @param bool $unattributed whether the amount goes to the `unattributed` bucket (false when the
     *     credit memo refunded the lines themselves, a full refund)
     * @return void
     */
    public function recordAutoCreditmemo(
        InfoInterface $payment,
        string $key,
        float $amount,
        string $creditmemoId,
        bool $unattributed
    ): void {
        $ledger = $this->read($payment);
        $amount = round($amount, 2);
        if ($unattributed) {
            $ledger['unattributed'] = round($ledger['unattributed'] + $amount, 2);
        }
        $ledger['auto_creditmemos'][] = [
            'key' => $key,
            'amount' => $amount,
            'creditmemo_id' => $creditmemoId,
        ];

        $this->write($payment, $ledger);
    }

    /**
     * Set the credit memo id of an automatic credit memo recorded before core assigned it.
     *
     * @param InfoInterface $payment
     * @param string $key
     * @param string $creditmemoId
     * @return void
     */
    public function setAutoCreditmemoId(InfoInterface $payment, string $key, string $creditmemoId): void
    {
        $ledger = $this->read($payment);
        foreach ($ledger['auto_creditmemos'] as $index => $entry) {
            if (($entry['key'] ?? null) === $key) {
                $ledger['auto_creditmemos'][$index]['creditmemo_id'] = $creditmemoId;
            }
        }

        $this->write($payment, $ledger);
    }

    /**
     * The decoded ledger, never throwing: a missing or corrupt value is an empty ledger.
     *
     * @param InfoInterface|null $payment
     * @return array{lines: array<string,float>, unattributed: float, auto_creditmemos: array}
     */
    public function read(?InfoInterface $payment): array
    {
        $ledger = ['lines' => [], 'unattributed' => 0.0, 'auto_creditmemos' => []];
        $stored = $payment?->getAdditionalInformation(self::INFO_KEY);
        if (!is_string($stored) || $stored === '') {
            return $ledger;
        }

        try {
            $decoded = $this->json->unserialize($stored);
        } catch (\InvalidArgumentException) {
            return $ledger;
        }
        if (!is_array($decoded)) {
            return $ledger;
        }

        foreach (is_array($decoded['lines'] ?? null) ? $decoded['lines'] : [] as $key => $amount) {
            if (is_scalar($amount) && is_numeric($amount)) {
                $ledger['lines'][(string)$key] = (float)$amount;
            }
        }
        $unattributed = $decoded['unattributed'] ?? 0;
        $ledger['unattributed'] = is_scalar($unattributed) && is_numeric($unattributed) ? (float)$unattributed : 0.0;
        foreach (is_array($decoded['auto_creditmemos'] ?? null) ? $decoded['auto_creditmemos'] : [] as $entry) {
            if (is_array($entry)) {
                $ledger['auto_creditmemos'][] = $entry;
            }
        }

        return $ledger;
    }

    /**
     * Store the ledger on the payment.
     *
     * @param InfoInterface $payment
     * @param array $ledger
     * @return void
     */
    private function write(InfoInterface $payment, array $ledger): void
    {
        $ledger['lines'] = (object)$ledger['lines'];
        $payment->setAdditionalInformation(self::INFO_KEY, $this->json->serialize($ledger));
    }
}
