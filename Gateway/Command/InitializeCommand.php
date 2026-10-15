<?php
/**
 * Copyright © 2026 NovaPay. All rights reserved.
 * Licensed under the MIT License. See LICENSE for details.
 */
declare(strict_types=1);

namespace NovaPay\Payment\Gateway\Command;

use Magento\Framework\DataObject;
use Magento\Payment\Gateway\CommandInterface;
use Magento\Payment\Gateway\Data\PaymentDataObjectInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Config as OrderConfig;
use NovaPay\Payment\Gateway\Config;
use NovaPay\Payment\Model\Order\StatusCode;
use Psr\Log\LoggerInterface;

/**
 * "initialize" gateway command, Magento's hook for order placement when the payment method cannot
 * authorize/capture synchronously (driven by `can_initialize`).
 *
 * NovaPay is a redirect gateway: at order-placement time no NovaPay session exists yet, so there is
 * nothing to authorize or capture. This command only sets the order to state `new` / the configured
 * "created" status (default `novapay_pending`) and leaves the customer un-notified; the redirect
 * controller ({@see \NovaPay\Payment\Controller\Redirect\Index}) performs the actual `/v1/session`
 * and `/v1/payment` calls afterwards. Without this command, `payment_action=authorize_capture` with
 * an empty command pool makes order placement throw the moment the method is enabled.
 *
 * The configured "created" status is validated to actually belong to Magento's `new` state via
 * {@see OrderConfig::getStateStatuses()}; if it doesn't, a warning is logged and the hardcoded
 * default is used instead.
 *
 * Order state changes belong only to PostbackProcessor/StatusMapper — this command is the sole,
 * narrow exception, because it runs before any NovaPay session exists and only ever produces the
 * single fixed "just placed, pending payment" state.
 *
 * @api
 */
class InitializeCommand implements CommandInterface
{
    private const DEFAULT_STATUS = StatusCode::PENDING;

    /**
     * @param Config $config
     * @param OrderConfig $orderConfig
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly Config $config,
        private readonly OrderConfig $orderConfig,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @inheritDoc
     */
    public function execute(array $commandSubject)
    {
        $stateObject = $commandSubject['stateObject'] ?? null;
        if (!$stateObject instanceof DataObject) {
            return null;
        }

        $storeId = null;
        $payment = $commandSubject['payment'] ?? null;
        if ($payment instanceof PaymentDataObjectInterface) {
            $storeId = (int)$payment->getOrder()->getStoreId();
        }

        $status = $this->resolveCreatedStatus($this->config->getMappedOrderStatus('created', $storeId));

        $stateObject->setData('state', Order::STATE_NEW);
        $stateObject->setData('status', $status);
        $stateObject->setData('is_notified', false);

        return null;
    }

    /**
     * Fall back to {@see DEFAULT_STATUS} when nothing is configured or valid for the `new` state.
     *
     * @param string $configuredStatus
     * @return string
     */
    private function resolveCreatedStatus(string $configuredStatus): string
    {
        if ($configuredStatus === '') {
            return self::DEFAULT_STATUS;
        }

        $validStatuses = $this->orderConfig->getStateStatuses(Order::STATE_NEW, false);
        if (!in_array($configuredStatus, $validStatuses, true)) {
            $this->logger->warning(
                'NovaPay "Status: Created" is not a valid status for the "new" order state; '
                . 'falling back to the default.',
                [
                    'event' => 'config.status_label_invalid',
                    'configured_status' => $configuredStatus,
                    'default_status' => self::DEFAULT_STATUS,
                ]
            );

            return self::DEFAULT_STATUS;
        }

        return $configuredStatus;
    }
}
