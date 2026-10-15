<?php
/**
 * Copyright © 2026 NovaPay. All rights reserved.
 * Licensed under the MIT License. See LICENSE for details.
 */
declare(strict_types=1);

namespace NovaPay\Payment\Model\Postback;

use Magento\Sales\Model\Order;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use Psr\Log\LoggerInterface;

/**
 * Matches a {@see ParsedPostback} to the Magento order it belongs to.
 *
 * Matches by **session id first** — it is globally unique per checkout attempt, whereas
 * `external_id` (the increment_id we sent) is only unique *at the time of the original request*
 * — a retried checkout reuses the same increment_id across an orphaned old session and a fresh
 * new one, so `external_id` is only the *fallback*: if the session id doesn't resolve to any
 * order, but a plausible `external_id` does, that match is still useful for logging/diagnostics
 * (the caller's own session-match guard rejects it if stale).
 *
 * Both lookups use {@see OrderCollectionFactory} rather than a service contract: the fallback
 * lookup (session id nested inside the JSON-serialized `sales_order_payment.additional_information`
 * column) needs a direct collection filter against the joined payment table — no service contract
 * supports filtering on a key inside a serialized blob. No raw SQL; values are bound via the
 * connection's own quoting.
 *
 * @api
 */
class OrderMatcher
{
    private const PAYMENT_TABLE = 'sales_order_payment';
    private const INFO_SESSION_ID = 'novapay_session_id';

    /**
     * @param OrderCollectionFactory $orderCollectionFactory
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly OrderCollectionFactory $orderCollectionFactory,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * The order this postback belongs to, or null if none matches by either session id or external_id.
     *
     * @param ParsedPostback $parsed
     * @return Order|null
     */
    public function match(ParsedPostback $parsed): ?Order
    {
        $sessionId = $parsed->getSessionId();

        $order = $this->matchBySessionId($sessionId);
        if ($order !== null) {
            return $order;
        }

        $order = $this->matchByExternalId($parsed->getExternalId());
        if ($order === null) {
            return null;
        }

        $orderSessionId = $this->currentSessionId($order);
        if ($orderSessionId !== '' && $orderSessionId === trim($sessionId)) {
            return $order;
        }

        // The external_id fallback matched an order, but that order's own *current* NovaPay session
        // does not correspond to this postback's session id at all — reject the match outright
        // rather than hand back a possibly-wrong order (the caller,
        // {@see \NovaPay\Payment\Model\PostbackProcessor}, additionally re-verifies the session id
        // as defense in depth, but OrderMatcher's own contract stays "only return a match the
        // session id lookup would also stand behind"). Logged here so a match rejected at this site
        // is not silent.
        $this->logger->warning(
            'NovaPay postback external_id fallback match rejected: matched order\'s own current '
            . 'session does not correspond to this postback\'s session id (stale or orphaned '
            . 'session).',
            [
                'event' => 'postback.stale_session',
                'order_increment_id' => $order->getIncrementId(),
                'postback_session_id' => trim($sessionId),
                'order_session_id' => $orderSessionId,
            ]
        );

        return null;
    }

    /**
     * Primary match: NovaPay session id, as stored by
     * {@see \NovaPay\Payment\Model\CheckoutOrchestrator} in `payment.additional_information.novapay_session_id`.
     * Globally unique per checkout attempt — safer to trust than `external_id` (see class docblock).
     *
     * @param string $sessionId
     * @return Order|null
     */
    private function matchBySessionId(string $sessionId): ?Order
    {
        $sessionId = trim($sessionId);
        if ($sessionId === '') {
            return null;
        }

        $collection = $this->orderCollectionFactory->create();
        $paymentTable = $collection->getResource()->getTable(self::PAYMENT_TABLE);

        // additional_information is a serialized JSON blob; matching a nested key needs a raw LIKE
        // against the joined payment table (see class docblock) — the value is bound as a plain
        // scalar via Select::where()'s own quoting (Zend_Db_Select::_quote()), not string-concatenated
        // into SQL, so this is not susceptible to injection despite building the LIKE pattern by hand.
        // The session id itself is escaped for LIKE metacharacters — a session id containing a
        // literal `%`/`_` must not be interpreted as a wildcard.
        $collection->getSelect()->join(
            ['novapay_payment' => $paymentTable],
            'novapay_payment.parent_id = main_table.entity_id',
            []
        )->where(
            'novapay_payment.additional_information LIKE ?',
            // phpcs:ignore Magento2.Functions.DiscouragedFunction
            '%"novapay_session_id":"' . addcslashes($sessionId, '%_\\') . '"%'
        );
        $collection->setPageSize(1);

        /** @var Order|null $order */
        $order = $collection->getFirstItem();

        return $order !== null && $order->getId() ? $order : null;
    }

    /**
     * Fallback match: `payments[].external_id` = the order's increment_id. Unique only *at the time
     * of the original checkout request* — see class docblock for why this is the fallback, not the
     * primary lookup.
     *
     * @param string|null $externalId
     * @return Order|null
     */
    private function matchByExternalId(?string $externalId): ?Order
    {
        if ($externalId === null || trim($externalId) === '') {
            return null;
        }

        $collection = $this->orderCollectionFactory->create();
        $collection->addFieldToFilter('increment_id', trim($externalId));
        $collection->setPageSize(1);

        /** @var Order|null $order */
        $order = $collection->getFirstItem();

        return $order !== null && $order->getId() ? $order : null;
    }

    /**
     * `$order`'s own currently stored `novapay_session_id` (see {@see match()}).
     *
     * Returns `''` if the order has no payment record or no stored session id at all.
     *
     * @param Order $order
     * @return string
     */
    private function currentSessionId(Order $order): string
    {
        $payment = $order->getPayment();
        if ($payment === null) {
            return '';
        }

        return trim((string)($payment->getAdditionalInformation(self::INFO_SESSION_ID) ?? ''));
    }
}
