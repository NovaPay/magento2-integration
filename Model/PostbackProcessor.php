<?php
/**
 * Copyright © 2026 NovaPay. All rights reserved.
 * Licensed under the MIT License. See LICENSE for details.
 */
declare(strict_types=1);

namespace NovaPay\Payment\Model;

use Magento\Framework\Lock\LockManagerInterface;
use Magento\Sales\Model\Order;
use NovaPay\Payment\Api\SignatureServiceInterface;
use NovaPay\Payment\Gateway\Config;
use NovaPay\Payment\Gateway\Exception\InvalidKeyException;
use NovaPay\Payment\Gateway\Http\PanMasker;
use NovaPay\Payment\Model\Config\Source\PostbackVersion;
use NovaPay\Payment\Model\Postback\OrderMatcher;
use NovaPay\Payment\Model\Postback\ParsedPostback;
use NovaPay\Payment\Model\Postback\ParserPool;
use Psr\Log\LoggerInterface;

/**
 * The postback pipeline, driven by {@see \NovaPay\Payment\Controller\Postback\Index}. Strict
 * order — nothing downstream of a failed step ever runs:
 *
 * 0. Reject an empty body, or one larger than {@see MAX_BODY_BYTES}, before hashing/verifying/
 *    parsing anything.
 * 0a. Log the received payload itself (`postback.received`, INFO) — every postback that clears the
 *    two bounds checks above, valid or not, so the raw body behind any later verdict is always on
 *    record (requirements section 9). The body goes through {@see PanMasker::maskJson()} first: a
 *    PAN is never written to the log, whatever the payload contains. Logged BEFORE signature
 *    verification precisely so a rejected postback is still recoverable from the log; nothing is
 *    parsed out of the payload here, it is only masked and written as one opaque string.
 * 1. Verify the RSA-SHA256 signature of the exact raw body against the configured NovaPay public
 *    key. Nothing is parsed from the payload before this passes — invalid/missing signature stops
 *    here (logged; the controller still answers HTTP 200).
 * 2. Parse per the configured postback version ({@see ParserPool}); an unparsable payload stops here.
 * 2b. Take a blocking per-session lock ({@see LockManagerInterface}, name from {@see buildLockName()},
 *    wait at most {@see LOCK_TIMEOUT_SECONDS}) around steps 3-6, released in `finally`. The order
 *    is loaded only after the lock is held, so a second concurrent event for the same session (a
 *    NovaPay retry racing another retry or the get-status cron, each with a different body and so
 *    a different hash) sees the first one's saved writes and cannot create a second invoice. Not
 *    acquired in time → stop, logged as a WARNING (`postback.lock_timeout`); NovaPay retries and the
 *    cron picks the order up later.
 * 3. Match the order ({@see OrderMatcher}: primary `payments[].external_id`, fallback session id);
 *    unknown → stop.
 * 4. The postback's session id must equal the order's *current* `novapay_session_id` — a stale
 *    postback from an orphaned/retried-checkout session must never touch an order that has since
 *    moved on to a newer session. Mismatch → stop (logged as a warning, not a hard error: this is
 *    expected for abandoned-then-retried checkouts).
 * 5. Idempotency: sha256 of the payload against the order's bounded postback-hash history
 *    ({@see StatusMapper::hasProcessedHash()}, a *list* of recent hashes, not just the single most
 *    recent one) — a repeat of any of them is a duplicate → stop (logged as info: a legitimate
 *    at-least-once delivery retry, not a problem).
 * 6. {@see StatusMapper::apply()}, which itself re-checks status-progression before writing
 *    anything — the only step that can write to the order.
 *
 * Steps 2b-6 ({@see matchAndApply()}) are shared verbatim with the get-status cron fallback
 * ({@see \NovaPay\Payment\Cron\SyncStatus}) via {@see applyParsed()} — "one applier" for both paths.
 *
 * Every stopping point is logged; this class never throws — the controller always answers HTTP 200
 * regardless of outcome, so logging is the only signal for diagnosing a rejected postback. Each
 * event carries an `event` context key naming the verdict (`postback.invalid_signature`,
 * `postback.unknown_session`, `postback.stale_session`, `postback.duplicate`, `postback.lock_timeout`,
 * `postback.store_mismatch`, `postback.store_key_mismatch_rejected`,
 * `postback.blocked_transition`, `postback.accepted` — the last two from {@see StatusMapper}), on
 * top of the `postback.received` entry every postback gets before verification. Only the INFO-level
 * lines — `postback.received` and the duplicate verdict ("nothing happened") — are gated on
 * {@see Config::isLoggingEnabled()}, the same gate {@see \NovaPay\Payment\Gateway\Http\Client}'s
 * `api.request`/`api.response` entries use; every rejection verdict, including WARNING-level ones,
 * is unconditional.
 *
 * Signature verification and postback-version selection both read module config at the scope of
 * the store the *caller* resolved for the incoming request — never a store id taken from the
 * payload itself (order_id/external_id/metadata), since nothing in the payload can be trusted
 * before the signature verifies. The real postback path ({@see
 * \NovaPay\Payment\Controller\Postback\Index}) resolves that store from the request's own domain/
 * store code via {@see \Magento\Store\Model\StoreManagerInterface::getStore()} — safe, because
 * {@see \NovaPay\Payment\Model\CheckoutOrchestrator} always builds `callback_url` from the ordering
 * store's own base URL, so a genuine postback always arrives at that same store's URL.
 *
 * A null `$storeId` (any caller that cannot resolve one) is NOT "the default scope": Magento reads
 * it as {@see \Magento\Store\Model\ScopeInterface::SCOPE_STORE} for the *current* store, which in
 * a web request is the request's own store and in CLI/cron is the admin store (i.e. the default
 * values). Passing the store id explicitly therefore changes nothing about which values a normal
 * postback resolves — the previous implementation was already implicitly request-store-scoped —
 * it only makes that scope explicit, correct for CLI/cron callers, and testable.
 *
 * Once the order is matched (step 3 below), if its actual store differs from the request-resolved
 * store, that is logged as a WARNING (`postback.store_mismatch`). Processing then continues only
 * while both stores are configured with the *same* NovaPay public key (the normal multi-store case
 * — one account, key inherited from website/default — and every single-store install); if the keys
 * differ, the verified signature belongs to a different NovaPay account than the order's own store
 * trusts, so the postback is refused and logged CRITICAL
 * (`postback.store_key_mismatch_rejected`). See {@see passesCrossStoreKeyCheck()}.
 *
 * Which header carries the signature depends on the configured postback version — see
 * {@see signatureHeaderForVersion()}. Confirmed against a live v3 postback (2026-08-13):
 * `x-sign` does NOT verify for v3, `x-sign-v2` does; the `x-sign` name is only correct for the
 * legacy v1/v2 payload shapes.
 *
 * @api
 */
class PostbackProcessor
{
    /**
     * The signature header used for the legacy v1/v2 postback payload shapes.
     */
    public const HEADER_SIGNATURE_LEGACY = 'x-sign';

    /**
     * The signature header used for the v3 postback payload shape — confirmed by verifying a live
     * v3 postback against the configured NovaPay public key: `x-sign` does not verify, `x-sign-v2`
     * does. See {@see signatureHeaderForVersion()}.
     */
    public const HEADER_SIGNATURE_V3 = 'x-sign-v2';

    /**
     * Reject any body larger than this before doing anything else — a real NovaPay postback is a
     * small JSON object; an oversized body is either garbage or an attempted resource-exhaustion
     * probe, and there is no reason to even hash/verify/parse it.
     */
    private const MAX_BODY_BYTES = 65536;

    private const INFO_SESSION_ID = 'novapay_session_id';

    /**
     * Longest wait for the per-session apply lock (step 2b) before giving up on this event.
     */
    private const LOCK_TIMEOUT_SECONDS = 10;

    /**
     * Prefix of the per-session apply lock name, see {@see buildLockName()}.
     */
    private const LOCK_NAME_PREFIX = 'novapay_pb_';

    /**
     * Hex characters of the session id's sha256 kept in the lock name: prefix (11) + 32 = 43
     * characters, leaving room for the installation prefix
     * {@see \Magento\Framework\Lock\Backend\Database} adds within MySQL's 64-character
     * GET_LOCK limit.
     */
    private const LOCK_NAME_HASH_LENGTH = 32;

    /**
     * @param Config $config
     * @param SignatureServiceInterface $signatureService
     * @param ParserPool $parserPool
     * @param OrderMatcher $orderMatcher
     * @param StatusMapper $statusMapper
     * @param LoggerInterface $logger
     * @param PanMasker $panMasker the module's single masking rule, shared with
     *     {@see \NovaPay\Payment\Gateway\Http\Client} — applied to every logged postback body
     * @param LockManagerInterface $lockManager per-session apply lock (step 2b)
     */
    public function __construct(
        private readonly Config $config,
        private readonly SignatureServiceInterface $signatureService,
        private readonly ParserPool $parserPool,
        private readonly OrderMatcher $orderMatcher,
        private readonly StatusMapper $statusMapper,
        private readonly LoggerInterface $logger,
        private readonly PanMasker $panMasker,
        private readonly LockManagerInterface $lockManager
    ) {
    }

    /**
     * Which header a postback's signature is delivered in, for the given configured postback
     * version — confirmed by verifying a real v3 postback offline against the configured NovaPay
     * public key: `x-sign` does NOT verify for v3, `x-sign-v2` does. v1/v2 keep using `x-sign`
     * (unconfirmed by live evidence, but unchanged from the original assumption and not part of
     * this fix). Anything other than v1/v2 — including an unrecognized value — is treated as v3,
     * matching {@see Config::getPostbackVersion()}'s own v3 default.
     *
     * Used by {@see process()} to name the header a signature *should* have arrived in when none
     * did — a diagnostic only. The controller no longer consults this mapping to decide what to
     * read: it accepts whichever of the two headers is actually present (see
     * {@see \NovaPay\Payment\Controller\Postback\Index::execute()}), since the same public key
     * verifies the body either way.
     *
     * Deliberately `static`: a pure, stateless mapping with no dependency on this service's own
     * collaborators, and the module's single documented source of truth for which header carries
     * which version's signature. phpcs flags all statics as "cannot be intercepted"; that
     * plugin-interception concern does not apply to a pure function with no side effects and
     * nothing to intercept.
     *
     * @param string $version one of {@see PostbackVersion}'s VERSION_* values
     * @return string the header name to read the signature from, lowercase
     */
    // phpcs:ignore Magento2.Functions.StaticFunction.StaticFunction
    public static function signatureHeaderForVersion(string $version): string
    {
        return match ($version) {
            PostbackVersion::VERSION_V1, PostbackVersion::VERSION_V2 => self::HEADER_SIGNATURE_LEGACY,
            default => self::HEADER_SIGNATURE_V3,
        };
    }

    /**
     * Entry point for a real postback: raw request body + the value of the version-appropriate
     * signature header (see {@see signatureHeaderForVersion()}) — the caller
     * ({@see \NovaPay\Payment\Controller\Postback\Index}) is responsible for reading the right
     * header off the request before calling this.
     *
     * @param string $rawBody exact raw bytes received — never re-encoded before verification
     * @param string $signature base64 signature from the version-appropriate signature header, or
     *     '' if absent
     * @param int|null $storeId the store resolved for the *request itself* (never derived from the
     *     payload) — see class docblock; null means the current store (the admin store, i.e. the
     *     default values, in CLI/cron)
     * @return void
     */
    public function process(string $rawBody, string $signature, ?int $storeId = null): void
    {
        if ($rawBody === '') {
            $this->logger->warning(
                'NovaPay postback rejected: empty body.',
                ['event' => 'postback.malformed', 'reason' => 'empty_body', 'request_store_id' => $storeId]
            );

            return;
        }

        if (strlen($rawBody) > self::MAX_BODY_BYTES) {
            $this->logger->warning('NovaPay postback rejected: body exceeds the maximum accepted size.', [
                'event' => 'postback.malformed',
                'reason' => 'oversized_body',
                'body_bytes' => strlen($rawBody),
                'max_bytes' => self::MAX_BODY_BYTES,
                'request_store_id' => $storeId,
            ]);

            return;
        }

        $version = $this->config->getPostbackVersion($storeId);
        $expectedHeader = self::signatureHeaderForVersion($version);
        $signaturePresent = trim($signature) !== '';

        // Requirements section 9: EVERY received postback — valid or invalid — is logged with its
        // payload. Written here, before verification, so the body behind an invalid-signature or
        // malformed verdict is on record too; the body is bounded by MAX_BODY_BYTES at this point
        // (checked above), and PanMasker::maskJson() masks any PAN/phone in it (falling back to its
        // own blind-regex mask() when the body is not parsable JSON, so a masked string is logged
        // either way). Gated on the same "Enable Logging" toggle as Client's api.request/
        // api.response entries — routine telemetry, unlike the WARNING+ verdicts below.
        if ($this->config->isLoggingEnabled($storeId)) {
            $this->logger->info('NovaPay postback received.', [
                'event' => 'postback.received',
                'body' => $this->panMasker->maskJson($rawBody),
                'body_sha256' => hash('sha256', $rawBody),
                'body_bytes' => strlen($rawBody),
                'signature_present' => $signaturePresent,
                'postback_version' => $version,
                'request_store_id' => $storeId,
            ]);
        }

        if (!$signaturePresent) {
            $this->logger->warning('NovaPay postback rejected: missing signature header.', [
                'event' => 'postback.invalid_signature',
                'reason' => 'missing_header',
                'expected_header' => $expectedHeader,
                'postback_version' => $version,
                'body_sha256' => hash('sha256', $rawBody),
                'request_store_id' => $storeId,
            ]);

            return;
        }

        try {
            $isValid = $this->signatureService->verify($rawBody, $signature, $this->config->getPublicKey($storeId));
        } catch (InvalidKeyException $e) {
            $this->logger->critical('NovaPay postback signature verification failed: invalid public key configured.', [
                'event' => 'postback.invalid_signature',
                'reason' => 'invalid_public_key_configured',
                'exception' => $e->getMessage(),
                'request_store_id' => $storeId,
            ]);

            return;
        }

        if (!$isValid) {
            $this->logger->warning('NovaPay postback rejected: signature does not verify.', [
                'event' => 'postback.invalid_signature',
                'reason' => 'signature_mismatch',
                'body_sha256' => hash('sha256', $rawBody),
                'request_store_id' => $storeId,
            ]);

            return;
        }

        // Signature verified — safe to parse the payload from here on.
        try {
            $decoded = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $this->logger->error('NovaPay postback rejected: signature valid but body is not valid JSON.', [
                'event' => 'postback.malformed',
                'reason' => 'invalid_json',
                'exception' => $e->getMessage(),
            ]);

            return;
        }

        if (!is_array($decoded)) {
            $this->logger->error('NovaPay postback rejected: signature valid but body did not decode to an object.', [
                'event' => 'postback.malformed',
                'reason' => 'not_an_object',
            ]);

            return;
        }

        $parser = $this->parserPool->get($version);
        if ($parser === null) {
            $this->logger->error('NovaPay postback rejected: no parser registered for the configured version.', [
                'event' => 'postback.malformed',
                'reason' => 'no_parser_for_version',
                'version' => $version,
                'request_store_id' => $storeId,
            ]);

            return;
        }

        $parsed = $parser->parse($decoded);
        if ($parsed === null) {
            // The parser itself already logged why (missing fields / not implemented).
            return;
        }

        $this->matchAndApply($parsed, hash('sha256', $rawBody), $storeId, OrderStatusApplier::SOURCE_POSTBACK);
    }

    /**
     * Entry point for the get-status cron fallback: a {@see ParsedPostback} already built from a
     * `/v1/get-status` response (which is trusted — it came back over the signed, TLS request
     * *we* made, not an inbound webhook — so no signature verification applies here), plus a hash
     * of that response for the same duplicate-suppression semantics as a real postback.
     *
     * @param ParsedPostback $parsed
     * @param string $payloadHash
     * @param int|null $storeId the store the caller already knows this response belongs to — the
     *     cron passes the *order's* own store (it polled that order deliberately, so unlike an
     *     inbound postback there is nothing untrusted about it); null means the current store,
     *     which in CLI/cron is the admin store (the default values)
     * @return void
     */
    public function applyParsed(ParsedPostback $parsed, string $payloadHash, ?int $storeId = null): void
    {
        $this->matchAndApply($parsed, $payloadHash, $storeId, OrderStatusApplier::SOURCE_GET_STATUS);
    }

    /**
     * The shared tail of both entry points.
     *
     * Per-session lock → match → store-mismatch diagnostic → session-match guard → idempotency →
     * {@see StatusMapper::apply()} → unlock (always, in `finally`).
     *
     * @param ParsedPostback $parsed
     * @param string $payloadHash
     * @param int|null $storeId the store the caller resolved (request store for a postback, the
     *     order's own store for the cron); null = the current store (admin store in CLI/cron)
     * @param string $source {@see OrderStatusApplier::SOURCE_POSTBACK} or
     *     {@see OrderStatusApplier::SOURCE_GET_STATUS}, shown in the order history entry
     * @return void
     */
    private function matchAndApply(
        ParsedPostback $parsed,
        string $payloadHash,
        ?int $storeId,
        string $source
    ): void {
        $lockName = $this->buildLockName($parsed->getSessionId());

        try {
            $locked = $this->lockManager->lock($lockName, self::LOCK_TIMEOUT_SECONDS);
            $lockError = null;
        } catch (\Throwable $e) {
            $locked = false;
            $lockError = $e->getMessage();
        }

        if (!$locked) {
            $this->logger->warning(
                'NovaPay postback not applied: another process is applying an event for the same '
                . 'session and the lock was not released in time; NovaPay retries and the get-status '
                . 'cron will apply it later.',
                [
                    'event' => 'postback.lock_timeout',
                    'session_id' => $parsed->getSessionId(),
                    'external_id' => $parsed->getExternalId(),
                    'novapay_status' => $parsed->getStatus(),
                    'source' => $source,
                    'lock_timeout_seconds' => self::LOCK_TIMEOUT_SECONDS,
                    'request_store_id' => $storeId,
                ] + ($lockError === null ? [] : ['exception' => $lockError])
            );

            return;
        }

        try {
            $this->applyUnderLock($parsed, $payloadHash, $storeId, $source);
        } finally {
            $this->lockManager->unlock($lockName);
        }
    }

    /**
     * Deterministic per-session lock name: {@see LOCK_NAME_PREFIX} + the first
     * {@see LOCK_NAME_HASH_LENGTH} hex characters of the session id's sha256 (43 characters in
     * total, whatever the session id looks like).
     *
     * @param string $sessionId
     * @return string
     */
    private function buildLockName(string $sessionId): string
    {
        return self::LOCK_NAME_PREFIX . substr(hash('sha256', $sessionId), 0, self::LOCK_NAME_HASH_LENGTH);
    }

    /**
     * Steps 3-6, run only while the per-session lock is held (see {@see matchAndApply()}).
     *
     * @param ParsedPostback $parsed
     * @param string $payloadHash
     * @param int|null $storeId
     * @param string $source
     * @return void
     */
    private function applyUnderLock(
        ParsedPostback $parsed,
        string $payloadHash,
        ?int $storeId,
        string $source
    ): void {
        $order = $this->orderMatcher->match($parsed);
        if ($order === null) {
            $this->logger->warning('NovaPay postback rejected: no matching order found.', [
                'event' => 'postback.unknown_session',
                'session_id' => $parsed->getSessionId(),
                'external_id' => $parsed->getExternalId(),
                'request_store_id' => $storeId,
            ]);

            return;
        }

        $orderStoreId = (int)$order->getStoreId();
        if ($storeId !== null && $orderStoreId !== $storeId && !$this->passesCrossStoreKeyCheck($order, $storeId)) {
            return;
        }

        $payment = $order->getPayment();
        if ($payment === null) {
            $this->logger->error('NovaPay postback rejected: matched order has no payment record.', [
                'event' => 'postback.malformed',
                'reason' => 'no_payment_record',
                'order_increment_id' => $order->getIncrementId(),
            ]);

            return;
        }

        $currentSessionId = trim((string)($payment->getAdditionalInformation(self::INFO_SESSION_ID) ?? ''));
        if ($currentSessionId === '' || $currentSessionId !== $parsed->getSessionId()) {
            $this->logger->warning(
                'NovaPay postback rejected: session id does not match the order\'s current session '
                . '(stale or orphaned session).',
                [
                    'event' => 'postback.stale_session',
                    'order_increment_id' => $order->getIncrementId(),
                    'postback_session_id' => $parsed->getSessionId(),
                    'order_session_id' => $currentSessionId,
                    'request_store_id' => $storeId,
                ]
            );

            return;
        }

        if ($this->statusMapper->hasProcessedHash($order, $payloadHash)) {
            if ($this->config->isLoggingEnabled($orderStoreId)) {
                $this->logger->info('NovaPay postback duplicate: identical payload already processed; skipping.', [
                    'event' => 'postback.duplicate',
                    'order_increment_id' => $order->getIncrementId(),
                    'novapay_status' => $parsed->getStatus(),
                    'request_store_id' => $storeId,
                ]);
            }

            return;
        }

        try {
            $this->statusMapper->apply($order, $parsed, $payloadHash, $source);
        } catch (\Throwable $e) {
            $this->logger->critical('NovaPay postback processing failed unexpectedly after verification.', [
                'event' => 'postback.error',
                'order_increment_id' => $order->getIncrementId(),
                'novapay_status' => $parsed->getStatus(),
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Whether a cross-store postback may still be applied.
     *
     * Called only when the matched order's store differs from the store the request resolved to.
     * The payload was verified against the *request* store's public key, so applying it to an order
     * in another store is only safe while both stores trust the same key — which is the normal
     * multi-store case (one NovaPay account, the key set once at website/default scope and
     * inherited) and every single-store install. When the two stores are configured with
     * *different* keys, the signature that was checked is not the one that governs the order's own
     * store, so this refuses to apply rather than let one store's key authorize a write to
     * another's order.
     *
     * Both outcomes are logged: the mismatch itself always as a WARNING
     * (`postback.store_mismatch`), the refusal additionally as CRITICAL
     * (`postback.store_key_mismatch_rejected`) — a genuine misconfiguration (wrong base URL, or a
     * store code missing from `callback_url`) that leaves an order unprocessed and needs a human.
     *
     * @param Order $order the matched order — trusted: the signature already verified
     * @param int $storeId the store the request resolved to
     * @return bool true = keep processing (same key, or same store), false = stop
     */
    private function passesCrossStoreKeyCheck(Order $order, int $storeId): bool
    {
        $orderStoreId = (int)$order->getStoreId();

        $this->logger->warning(
            'NovaPay postback: the matched order belongs to a different store than the one the '
            . 'request resolved to.',
            [
                'event' => 'postback.store_mismatch',
                'order_increment_id' => $order->getIncrementId(),
                'request_store_id' => $storeId,
                'order_store_id' => $orderStoreId,
            ]
        );

        $orderStoreKey = trim($this->config->getPublicKey($orderStoreId));
        $requestStoreKey = trim($this->config->getPublicKey($storeId));
        if (hash_equals($orderStoreKey, $requestStoreKey)) {
            return true;
        }

        $this->logger->critical(
            'NovaPay postback rejected: the matched order\'s store is configured with a different '
            . 'NovaPay public key than the store this request resolved to, so the verified '
            . 'signature does not authorize a write to this order.',
            [
                'event' => 'postback.store_key_mismatch_rejected',
                'order_increment_id' => $order->getIncrementId(),
                'request_store_id' => $storeId,
                'order_store_id' => $orderStoreId,
            ]
        );

        return false;
    }
}
