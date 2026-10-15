<?php
/**
 * Copyright © 2026 NovaPay. All rights reserved.
 * Licensed under the MIT License. See LICENSE for details.
 */
declare(strict_types=1);

namespace NovaPay\Payment\Gateway\Command;

use Magento\Framework\Exception\LocalizedException;
use Magento\Payment\Gateway\CommandInterface;
use Magento\Payment\Gateway\Helper\SubjectReader;
use Magento\Sales\Model\Order\Payment;
use NovaPay\Payment\Gateway\Config;
use NovaPay\Payment\Gateway\ErrorMapper;
use NovaPay\Payment\Gateway\Http\Client;
use NovaPay\Payment\Gateway\Http\Exception\ApiException;
use NovaPay\Payment\Gateway\Request\VoidBuilder;
use NovaPay\Payment\Model\Order\GatewayFailureNote;
use NovaPay\Payment\Model\Order\GatewayFailureNoteRegistry;
use Psr\Log\LoggerInterface;

/**
 * "void" + "cancel" gateway command — `POST /v1/void` used as a **hold cancellation**. Registered
 * at both pool slots because Magento exposes two distinct native admin actions that both end up
 * calling `MethodInterface::{void|cancel}()` with only `['payment' => ...]` (no amount) whenever
 * the order still has an open, unclosed authorization transaction — an order-level "Cancel" button
 * (`cancel` slot) and a payment-level "Void" button (`void` slot). Both represent the same NovaPay
 * operation; one implementation serves both. Neither native call ever carries an amount/qty, so
 * partial hold cancel needs no extra guard — there is no code path that could build one.
 *
 * **Recursion / redundant-call guard:** this command is also reached indirectly from this module's
 * own postback pipeline — {@see \NovaPay\Payment\Model\OrderStatusApplier::applyExpired()}
 * and the no-invoice branch of {@see \NovaPay\Payment\Model\OrderStatusApplier::applyVoided()} both
 * call {@see \Magento\Sales\Model\Order::cancel()} on an order whose open authorization
 * transaction is still there, which triggers this exact `cancel` slot. Calling NovaPay's `/v1/void`
 * again in that situation would be wrong (NovaPay already told us the session is dead) and likely
 * to error, aborting the postback's own state write. The guard is simply: only make the real API
 * call while the order's stored `novapay_status` is still `holded` —
 * {@see \NovaPay\Payment\Model\OrderStatusApplier::persistPaymentInfo()} always updates that field
 * *before* triggering any side effect that could reach this command, so a postback-driven cancel
 * always finds the status already moved past `holded` and is a safe no-op.
 *
 * @api
 */
class VoidCommand implements CommandInterface
{
    private const INFO_STATUS = 'novapay_status';
    private const INFO_SESSION_ID = 'novapay_session_id';

    /**
     * @param VoidBuilder $requestBuilder
     * @param Client $client
     * @param ErrorMapper $errorMapper
     * @param LoggerInterface $logger
     * @param Config $config
     * @param GatewayFailureNoteRegistry $failureNotes
     */
    public function __construct(
        private readonly VoidBuilder $requestBuilder,
        private readonly Client $client,
        private readonly ErrorMapper $errorMapper,
        private readonly LoggerInterface $logger,
        private readonly Config $config,
        private readonly GatewayFailureNoteRegistry $failureNotes
    ) {
    }

    /**
     * @inheritDoc
     *
     * @throws LocalizedException any mapped NovaPay API error
     */
    public function execute(array $commandSubject)
    {
        $paymentDO = SubjectReader::readPayment($commandSubject);

        /** @var Payment $payment */
        $payment = $paymentDO->getPayment();
        $order = $payment->getOrder();
        $storeId = (int)$order->getStoreId();

        $status = trim((string)$payment->getAdditionalInformation(self::INFO_STATUS));
        if ($status !== 'holded') {
            if ($this->config->isLoggingEnabled($storeId)) {
                $this->logger->info(
                    'NovaPay void/cancel command reached with no active hold to cancel (novapay_status '
                    . 'is not "holded"); treating as a no-op. This is expected when Magento\'s own '
                    . 'Order::cancel() side effect runs for a postback-driven failed/expired/voided '
                    . 'transition rather than a genuine admin-initiated hold cancellation.',
                    [
                        'event' => 'command.void_noop',
                        'order_increment_id' => $order->getIncrementId(),
                        'novapay_status' => $status,
                    ]
                );
            }

            return null;
        }

        $sessionId = trim((string)$payment->getAdditionalInformation(self::INFO_SESSION_ID));
        if ($sessionId === '') {
            throw new LocalizedException(__('This order has no NovaPay session to cancel the hold on.'));
        }

        $payload = $this->requestBuilder->build(['session_id' => $sessionId, 'store_id' => $storeId]);

        try {
            $this->client->request('/v1/void', $payload, $storeId);
        } catch (ApiException $e) {
            $this->logger->error('NovaPay void (cancel hold) call failed.', [
                'event' => 'command.void_error',
                'order_increment_id' => $order->getIncrementId(),
                'endpoint' => $e->getEndpoint(),
                'http_status' => $e->getHttpStatusCode(),
            ]);

            $error = $this->errorMapper->map($e);
            // Written to the order history by the admin controller after the rolled-back transaction.
            $this->failureNotes->add(new GatewayFailureNote(
                (int)$order->getEntityId(),
                GatewayFailureNote::OPERATION_VOID,
                null,
                (string)$order->getBaseCurrencyCode(),
                $error
            ));

            throw $error;
        }

        return null;
    }
}
