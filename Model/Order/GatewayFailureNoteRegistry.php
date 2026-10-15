<?php
/**
 * Copyright © 2026 NovaPay. All rights reserved.
 * Licensed under the MIT License. See LICENSE for details.
 */
declare(strict_types=1);

namespace NovaPay\Payment\Model\Order;

/**
 * Request-scoped list of failed NovaPay gateway calls (shared instance, see etc/di.xml).
 *
 * The capture/void/refund gateway commands run inside Magento's database transaction, which is
 * rolled back when the command throws, so an order history comment saved there would be lost. The
 * commands record the failure here instead, and {@see GatewayFailureHistoryWriter} writes it after
 * the transaction, from the admin controller.
 */
class GatewayFailureNoteRegistry
{
    /**
     * @var GatewayFailureNote[]
     */
    private array $notes = [];

    /**
     * Record a failed call.
     *
     * @param GatewayFailureNote $note
     * @return void
     */
    public function add(GatewayFailureNote $note): void
    {
        $this->notes[] = $note;
    }

    /**
     * Return and remove every recorded failure.
     *
     * @return GatewayFailureNote[]
     */
    public function takeAll(): array
    {
        $notes = $this->notes;
        $this->notes = [];

        return $notes;
    }
}
