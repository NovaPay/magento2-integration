<?php
/**
 * Copyright © 2026 NovaPay. All rights reserved.
 * Licensed under the MIT License. See LICENSE for details.
 */
declare(strict_types=1);

namespace NovaPay\Payment\Model\Refund;

use Magento\Sales\Model\Order;
use NovaPay\Payment\Gateway\Exception\InvalidKeyException;
use NovaPay\Payment\Gateway\Http\Client;
use NovaPay\Payment\Gateway\Http\Exception\ApiException;
use NovaPay\Payment\Gateway\Request\GetStatusBuilder;
use Psr\Log\LoggerInterface;

/**
 * Reads how much of a NovaPay session was refunded from its `operations[]` (`amount`,
 * `refunded_amount` per operation).
 *
 * NovaPay confirmed (2026-09-28) that after any partial refund of a single-operation session the
 * operation and the session become `voided`, and the postback carries no refund amounts. The amounts
 * are only available from `/v1/get-status`, which {@see fetch()} calls.
 *
 * `refunded_amount: null` means the operation is fully refunded (live-observed on a full void, see
 * {@see \NovaPay\Payment\Model\StatusMapper}), so it counts as the operation's own `amount`.
 */
class NovaPayRefundState
{
    private const ENDPOINT = '/v1/get-status';

    /**
     * @param Client $client
     * @param GetStatusBuilder $getStatusBuilder
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly Client $client,
        private readonly GetStatusBuilder $getStatusBuilder,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Ask NovaPay (`/v1/get-status`) how much of the session was refunded.
     *
     * Returns null, after logging a WARNING `refund.status_check_failed`, when the call fails or the
     * response has no usable operation data. The caller then decides from Magento's own ledger.
     *
     * @param Order $order
     * @param string $sessionId
     * @return RefundSnapshot|null
     */
    public function fetch(Order $order, string $sessionId): ?RefundSnapshot
    {
        $storeId = (int)$order->getStoreId();

        try {
            $response = $this->client->request(
                self::ENDPOINT,
                $this->getStatusBuilder->build(['session_id' => $sessionId, 'store_id' => $storeId]),
                $storeId
            );
        } catch (ApiException|InvalidKeyException|\InvalidArgumentException $e) {
            $this->logFailure($order, 'get-status call failed', $e->getMessage());

            return null;
        }

        $operations = $response['operations'] ?? null;
        $snapshot = is_array($operations) ? $this->fromOperations($operations) : null;
        if ($snapshot === null) {
            $this->logFailure($order, 'get-status response has no usable operation data', null);
        }

        return $snapshot;
    }

    /**
     * Sum `amount` and `refunded_amount` over `operations[]`.
     *
     * Null when there is no operation with a positive amount.
     *
     * @param array|null $operations
     * @return RefundSnapshot|null
     */
    public function fromOperations(?array $operations): ?RefundSnapshot
    {
        $amount = 0.0;
        $refunded = 0.0;
        $used = [];

        foreach ($operations ?? [] as $operation) {
            if (!is_array($operation) || !is_scalar($operation['amount'] ?? null)) {
                continue;
            }

            $operationAmount = (float)$operation['amount'];
            $refundedAmount = $operation['refunded_amount'] ?? null;
            $amount += $operationAmount;
            $refunded += is_scalar($refundedAmount) ? (float)$refundedAmount : $operationAmount;
            $used[] = $operation;
        }

        if ($used === [] || $amount <= 0.0) {
            return null;
        }

        return new RefundSnapshot($amount, $refunded, $used);
    }

    /**
     * WARNING `refund.status_check_failed`.
     *
     * @param Order $order
     * @param string $reason
     * @param string|null $error
     * @return void
     */
    private function logFailure(Order $order, string $reason, ?string $error): void
    {
        $this->logger->warning(
            'NovaPay refund state could not be read from get-status; deciding from the Magento ledger.',
            [
                'event' => 'refund.status_check_failed',
                'order_increment_id' => $order->getIncrementId(),
                'reason' => $reason,
                'error' => $error,
            ]
        );
    }
}
