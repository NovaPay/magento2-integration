<?php
/**
 * Copyright © 2026 NovaPay. All rights reserved.
 * Licensed under the MIT License. See LICENSE for details.
 */
declare(strict_types=1);

namespace NovaPay\Payment\Model\Postback;

/**
 * Immutable, version-agnostic view of a NovaPay postback payload, produced by a
 * {@see PostbackParserInterface} implementation from the raw decoded JSON body.
 *
 * Only the fields actually consumed downstream are modeled: `id` (session id), `status`,
 * `paytype`, `RRN`, `APPROVAL`, `card_details.{pan,card_bank,card_country,card_type}`,
 * `payments[].external_id`, `payments[].amount` / top-level `amount`. `operations` is NOT part of
 * the documented v3 schema; {@see getOperations()} is a defensive accessor (see class docblock on
 * {@see V3Parser}) and may legitimately be null.
 *
 * Not declared `final` (Magento coding standard prohibits it — decreases plugin/proxy extensibility).
 *
 * @api
 */
class ParsedPostback
{
    /**
     * @param string $sessionId NovaPay session id (`id` field) — never empty for a successfully parsed postback
     * @param string $status raw NovaPay session status (e.g. "paid", "holded", "processing_void")
     * @param string|null $externalId `payments[0].external_id` — the increment_id we originally sent, if present
     * @param string|null $paytype
     * @param string|null $rrn
     * @param string|null $approval
     * @param string|null $maskedPan already-masked PAN as received — never unmask, never store more
     * @param string|null $cardBank
     * @param string|null $cardCountry
     * @param string|null $cardType
     * @param array|null $operations raw `operations[]` array if present (not part of the
     *     documented v3 schema — see class docblock)
     * @param float|null $amount the reported paid/held amount, if present — `payments[0].amount`
     *     (documented postback schema) or, as a live-observed fallback, the top-level `amount` field
     *     (`/v1/get-status` response shape — see {@see V3Parser}); defaults to `null` so existing
     *     call sites that predate this field remain valid
     * @param string|null $createdAt NovaPay's own `created_at` timestamp as received (documented v3
     *     field, also present in the `/v1/get-status` response); shown in the order history entry
     */
    public function __construct(
        private readonly string $sessionId,
        private readonly string $status,
        private readonly ?string $externalId,
        private readonly ?string $paytype,
        private readonly ?string $rrn,
        private readonly ?string $approval,
        private readonly ?string $maskedPan,
        private readonly ?string $cardBank,
        private readonly ?string $cardCountry,
        private readonly ?string $cardType,
        private readonly ?array $operations,
        private readonly ?float $amount = null,
        private readonly ?string $createdAt = null
    ) {
    }

    /**
     * NovaPay session id (`id` field).
     *
     * @return string
     */
    public function getSessionId(): string
    {
        return $this->sessionId;
    }

    /**
     * Raw NovaPay session status (e.g. "paid", "holded", "processing_void").
     *
     * @return string
     */
    public function getStatus(): string
    {
        return $this->status;
    }

    /**
     * `payments[0].external_id` — the increment_id we originally sent, if present.
     *
     * @return string|null
     */
    public function getExternalId(): ?string
    {
        return $this->externalId;
    }

    /**
     * Payment method used, e.g. "card"|"apple_pay"|"google_pay"|"wallet".
     *
     * @return string|null
     */
    public function getPaytype(): ?string
    {
        return $this->paytype;
    }

    /**
     * Reference Retrieval Number of the card transaction.
     *
     * @return string|null
     */
    public function getRrn(): ?string
    {
        return $this->rrn;
    }

    /**
     * Card scheme approval code.
     *
     * @return string|null
     */
    public function getApproval(): ?string
    {
        return $this->approval;
    }

    /**
     * Already-masked PAN as received, e.g. "522712******0426".
     *
     * @return string|null
     */
    public function getMaskedPan(): ?string
    {
        return $this->maskedPan;
    }

    /**
     * Issuing bank of the card used, if known.
     *
     * @return string|null
     */
    public function getCardBank(): ?string
    {
        return $this->cardBank;
    }

    /**
     * Issuing country of the card used, if known.
     *
     * @return string|null
     */
    public function getCardCountry(): ?string
    {
        return $this->cardCountry;
    }

    /**
     * Card type/brand, if known.
     *
     * @return string|null
     */
    public function getCardType(): ?string
    {
        return $this->cardType;
    }

    /**
     * Raw `operations[]` if present in the payload, else null.
     *
     * @return array|null
     */
    public function getOperations(): ?array
    {
        return $this->operations;
    }

    /**
     * The reported paid/held amount, if present in the payload — see the constructor docblock for
     * which source field this is read from. `null` when unavailable, which callers must treat as
     * "cannot verify, do not block" rather than "verified as zero".
     *
     * CAVEAT (see {@see V3Parser::extractAmount()} for the full note): the documented-schema source
     * (`payments[0].amount`) may reflect the originally authorized/held amount rather than what was
     * actually captured for this specific event, not yet live-verified either way; and in a
     * multi-payment session (not used by this module today) the live-observed top-level `amount`
     * fallback is per-event, not the session total. Treat this value as the best available signal,
     * not a contractually-guaranteed capture amount.
     *
     * @return float|null
     */
    public function getAmount(): ?float
    {
        return $this->amount;
    }

    /**
     * NovaPay's `created_at` timestamp as received, if present.
     *
     * Display-only (order history entry); never parsed or used for any decision.
     *
     * @return string|null
     */
    public function getCreatedAt(): ?string
    {
        return $this->createdAt;
    }
}
