<?php
/**
 * Copyright © 2026 NovaPay. All rights reserved.
 * Licensed under the MIT License. See LICENSE for details.
 */
declare(strict_types=1);

namespace NovaPay\Payment\Model\Order;

/**
 * The six custom Magento order-status codes this module registers via
 * {@see \NovaPay\Payment\Setup\Patch\Data\AddOrderStatuses} (pending, holded, paid, expired) and
 * {@see \NovaPay\Payment\Setup\Patch\Data\AddFailedAndRefundedOrderStatuses} (failed, refunded),
 * and drives from the postback / get-status pipeline.
 *
 * `etc/config.xml`'s `<status_*>` defaults hold the same codes as literals and MUST be kept in
 * sync by hand: XML config values cannot reference PHP constants.
 *
 * Constants only: no state, no methods, never instantiated (`final` is omitted deliberately — the
 * Magento2 coding standard prohibits the keyword).
 *
 * @api
 */
class StatusCode
{
    /**
     * Order placed, NovaPay session created, payment not yet resolved (states `new`,
     * `pending_payment`).
     */
    public const PENDING = 'novapay_pending';

    /**
     * Funds authorized under the hold charge model, not yet captured (state `processing`).
     */
    public const HOLDED = 'novapay_holded';

    /**
     * Funds captured — direct payment or a confirmed hold (state `processing`).
     */
    public const PAID = 'novapay_paid';

    /**
     * NovaPay session expired before the customer paid (state `canceled`).
     */
    public const EXPIRED = 'novapay_expired';

    /**
     * NovaPay reported the payment attempt as failed; the order stays open (state
     * `pending_payment`) because the customer may retry in the same session. Also assigned to state
     * `canceled` for orders canceled by the earlier `failed` behavior. Distinct from core statuses
     * so a NovaPay-declined payment is recognizable.
     */
    public const FAILED = 'novapay_failed';

    /**
     * The whole NovaPay session was voided/refunded (state `closed`). Distinct from core `closed`
     * so a NovaPay refund is distinguishable from any other order close.
     */
    public const REFUNDED = 'novapay_refunded';
}
