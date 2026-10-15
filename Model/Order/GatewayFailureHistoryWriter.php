<?php
/**
 * Copyright © 2026 NovaPay. All rights reserved.
 * Licensed under the MIT License. See LICENSE for details.
 */
declare(strict_types=1);

namespace NovaPay\Payment\Model\Order;

use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Phrase;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderStatusHistoryInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Api\OrderStatusHistoryRepositoryInterface;
use Magento\Sales\Model\Order;
use Psr\Log\LoggerInterface;

/**
 * Writes the failed NovaPay gateway calls recorded in {@see GatewayFailureNoteRegistry} to the
 * order status history, after the failed operation's database transaction was rolled back.
 *
 * Called by the module's own admin controllers (refund, hold confirmation, hold release) and by
 * {@see \NovaPay\Payment\Plugin\Adminhtml\Order\GatewayFailureHistoryPlugin} after the native admin
 * credit memo, invoice, void and cancel actions. REST and CLI operations get no history comment:
 * nothing calls this class there, the flash message/API error is their only feedback.
 *
 * The order is read fresh from the database (a new instance from the repository list, never the
 * instance that took part in the rolled-back transaction), the comment keeps the current order
 * status, and only the history row is saved: order state and status are never changed. The comment
 * is admin-only (not customer-notified, not visible on the storefront) and rendered in the admin
 * locale by {@see AdminPhraseRenderer}, like the module's other order comments.
 */
class GatewayFailureHistoryWriter
{
    /**
     * @param GatewayFailureNoteRegistry $registry
     * @param OrderRepositoryInterface $orderRepository
     * @param OrderStatusHistoryRepositoryInterface $historyRepository
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param AdminPhraseRenderer $adminPhraseRenderer
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly GatewayFailureNoteRegistry $registry,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly OrderStatusHistoryRepositoryInterface $historyRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly AdminPhraseRenderer $adminPhraseRenderer,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Write every recorded failure to its order history. Never throws.
     *
     * @return void
     */
    public function writePending(): void
    {
        foreach ($this->registry->takeAll() as $note) {
            try {
                $this->write($note);
            } catch (\Throwable $e) {
                $this->logger->error('NovaPay: could not add the failed gateway call to the order history.', [
                    'event' => 'admin.gateway_failure_note_error',
                    'order_id' => $note->getOrderId(),
                    'operation' => $note->getOperation(),
                    'exception' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * The comment text for a note, before rendering.
     *
     * @param GatewayFailureNote $note
     * @return Phrase
     */
    public function buildComment(GatewayFailureNote $note): Phrase
    {
        $error = $note->getError();
        $details = $this->buildDetails($note);
        $amount = $note->getAmount() !== null
            ? number_format($note->getAmount(), 2, '.', '') . ' ' . $note->getCurrencyCode()
            : '';

        if ($error->isTransportFailure()) {
            return match ($note->getOperation()) {
                GatewayFailureNote::OPERATION_REFUND => __(
                    'NovaPay: the refund of %1 was not sent. %2',
                    $amount,
                    $details
                ),
                GatewayFailureNote::OPERATION_CAPTURE => __(
                    'NovaPay: the hold confirmation of %1 was not sent. %2',
                    $amount,
                    $details
                ),
                default => __('NovaPay: the hold release was not sent. %1', $details),
            };
        }

        return match ($note->getOperation()) {
            GatewayFailureNote::OPERATION_REFUND => __('NovaPay: the refund of %1 was rejected. %2', $amount, $details),
            GatewayFailureNote::OPERATION_CAPTURE => __(
                'NovaPay: the hold confirmation of %1 was rejected. %2',
                $amount,
                $details
            ),
            default => __('NovaPay: the hold release was rejected. %1', $details),
        };
    }

    /**
     * "<code>: <reason> NovaPay ref: <uuid>", leaving out the parts that are missing.
     *
     * @param GatewayFailureNote $note
     * @return Phrase
     */
    private function buildDetails(GatewayFailureNote $note): Phrase
    {
        $error = $note->getError();
        $reason = $error->getReason();
        $code = $error->isTransportFailure() ? null : $error->getErrorCode();
        $uuid = $error->getNovaPayUuid();

        if ($code !== null && $uuid !== null) {
            return __('%1: %2 NovaPay ref: %3', $code, $reason, $uuid);
        }
        if ($code !== null) {
            return __('%1: %2', $code, $reason);
        }
        if ($uuid !== null) {
            return __('%1 NovaPay ref: %2', $reason, $uuid);
        }

        return $reason;
    }

    /**
     * Add the comment to a freshly loaded order and save the history row only.
     *
     * @param GatewayFailureNote $note
     * @return void
     */
    private function write(GatewayFailureNote $note): void
    {
        $order = $this->loadFreshOrder($note->getOrderId());
        if ($order === null) {
            return;
        }

        $history = $order->addCommentToStatusHistory(
            $this->adminPhraseRenderer->render($this->buildComment($note))
        );
        if (!$history instanceof OrderStatusHistoryInterface) {
            return;
        }

        $history->setIsCustomerNotified(0);
        $history->setIsVisibleOnFront(0);
        $this->historyRepository->save($history);
    }

    /**
     * A new order instance read from the database, bypassing the repository's per-request cache.
     *
     * @param int $orderId
     * @return Order|null
     */
    private function loadFreshOrder(int $orderId): ?Order
    {
        $criteria = $this->searchCriteriaBuilder
            ->addFilter(OrderInterface::ENTITY_ID, $orderId)
            ->setPageSize(1)
            ->create();
        foreach ($this->orderRepository->getList($criteria)->getItems() as $order) {
            if ($order instanceof Order) {
                return $order;
            }
        }

        return null;
    }
}
