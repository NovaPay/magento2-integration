<?php
/**
 * Copyright © 2026 NovaPay. All rights reserved.
 * Licensed under the MIT License. See LICENSE for details.
 */
declare(strict_types=1);

namespace NovaPay\Payment\Gateway\Http;

use Magento\Framework\HTTP\Client\CurlFactory;
use NovaPay\Payment\Api\SignatureServiceInterface;
use NovaPay\Payment\Gateway\Config;
use NovaPay\Payment\Gateway\Http\Exception\ClientErrorException;
use NovaPay\Payment\Gateway\Http\Exception\ServerErrorException;
use NovaPay\Payment\Gateway\Http\Exception\TransportException;
use Psr\Log\LoggerInterface;

/**
 * Signed JSON HTTP client for the NovaPay Acquiring API.
 *
 * Owns the single code path that turns a payload into the exact raw body string that is both sent
 * and signed (json_encode($payload, JSON_UNESCAPED_UNICODE)) — request builders only ever produce
 * plain arrays, never pre-encoded strings, so there is no way for the signed string and the sent
 * string to diverge.
 *
 * Logs via the dedicated NovaPay Monolog channel (etc/di.xml redirects this class's "logger"
 * argument to {@see \NovaPay\Payment\Logger\VirtualLogger} — var/log/novapay.log), not
 * Magento's shared default channel. Every logged request/response body is masked via
 * {@see PanMasker::maskJson()}; the private key is never logged. Every context array below carries
 * an `event` key (dot-namespaced, e.g. `api.request`/`api.client_error`) alongside the
 * human-readable message, so a specific class of outcome can be grepped for reliably.
 *
 * INFO-level request/response logging is gated on {@see Config::isLoggingEnabled()}; every error/
 * critical line below is unconditional.
 *
 * @api
 */
class Client
{
    private const BASE_URL_TEST = 'https://api-qecom.novapay.ua';
    private const BASE_URL_LIVE = 'https://api-ecom.novapay.ua';

    private const CONNECT_TIMEOUT_SECONDS = 5;
    private const TOTAL_TIMEOUT_SECONDS = 30;

    /**
     * @param CurlFactory $curlFactory builds a fresh, non-shared Curl transport per request (Curl
     *     keeps request state — headers, timeouts — as instance properties) with TLS 1.2 pinned
     * @param Config $config
     * @param SignatureServiceInterface $signatureService
     * @param LoggerInterface $logger
     * @param PanMasker $panMasker
     */
    public function __construct(
        private readonly CurlFactory $curlFactory,
        private readonly Config $config,
        private readonly SignatureServiceInterface $signatureService,
        private readonly LoggerInterface $logger,
        private readonly PanMasker $panMasker
    ) {
    }

    /**
     * POST a signed JSON request to `{baseUrl}{endpoint}` and return the decoded JSON response body.
     *
     * @param string $endpoint e.g. "/v1/session"
     * @param array $payload plain payload array (merchant_id already included by the caller/builder)
     * @param int|null $storeId store scope to read Test Mode / Private Key from
     * @return array decoded JSON response body
     * @throws ClientErrorException NovaPay responded 4xx, or any status with a decoded body carrying
     *     both a `code` and one of `error`/`errors`/`type` (NovaPay's own error-body shape)
     * @throws ServerErrorException NovaPay responded 5xx, or with an unparsable/non-2xx-empty body
     * @throws TransportException no HTTP response was obtained (timeout, DNS, TLS, encoding failure, ...)
     */
    public function request(string $endpoint, array $payload, ?int $storeId = null): array
    {
        try {
            $rawBody = $this->encode($payload);
        } catch (\JsonException $e) {
            $this->logger->critical('NovaPay API request payload could not be encoded as JSON', [
                'event' => 'api.encode_error',
                'endpoint' => $endpoint,
                'error' => $e->getMessage(),
            ]);

            throw new TransportException(
                __('Unable to build the NovaPay request. Please try again shortly.'),
                $endpoint,
                null,
                null,
                $e
            );
        }

        $signature = $this->signatureService->sign($rawBody, $this->config->getPrivateKey($storeId));
        $url = $this->getBaseUrl($storeId) . $endpoint;

        if ($this->config->isLoggingEnabled($storeId)) {
            $this->logger->info('NovaPay API request', [
                'event' => 'api.request',
                'endpoint' => $endpoint,
                'body' => $this->panMasker->maskJson($rawBody),
            ]);
        }

        $curl = $this->curlFactory->create(['sslVersion' => CURL_SSLVERSION_TLSv1_2]);
        $curl->setOption(CURLOPT_CONNECTTIMEOUT, self::CONNECT_TIMEOUT_SECONDS);
        $curl->setTimeout(self::TOTAL_TIMEOUT_SECONDS);
        $curl->setHeaders([
            'Content-Type' => 'application/json',
            'x-sign' => $signature,
        ]);

        try {
            $curl->post($url, $rawBody);
        } catch (\Exception $e) {
            $this->logger->critical('NovaPay API transport failure', [
                'event' => 'api.transport_error',
                'endpoint' => $endpoint,
                'error' => $e->getMessage(),
            ]);

            throw new TransportException(
                __('Unable to reach the NovaPay payment gateway. Please try again shortly.'),
                $endpoint,
                null,
                null,
                $e
            );
        }

        $status = $curl->getStatus();
        $responseBody = (string)$curl->getBody();

        if ($this->config->isLoggingEnabled($storeId)) {
            $this->logger->info('NovaPay API response', [
                'event' => 'api.response',
                'endpoint' => $endpoint,
                'status' => $status,
                'body' => $this->panMasker->maskJson($responseBody),
            ]);
        }

        return $this->handleResponse($endpoint, $status, $responseBody);
    }

    /**
     * Map an HTTP status/body to either a decoded response array or a typed exception.
     *
     * @param string $endpoint
     * @param int $status
     * @param string $responseBody
     * @return array
     * @throws ClientErrorException
     * @throws ServerErrorException
     * @throws TransportException
     */
    private function handleResponse(string $endpoint, int $status, string $responseBody): array
    {
        if ($status === 0) {
            $this->logger->critical('NovaPay API returned no HTTP status (transport failure)', [
                'event' => 'api.transport_error',
                'endpoint' => $endpoint,
            ]);

            throw new TransportException(
                __('Unable to reach the NovaPay payment gateway. Please try again shortly.'),
                $endpoint
            );
        }

        if ($status >= 500) {
            $this->logger->critical('NovaPay API server error', [
                'event' => 'api.server_error',
                'endpoint' => $endpoint,
                'status' => $status,
                'body' => $this->panMasker->maskJson($responseBody),
            ]);

            throw new ServerErrorException(
                __('The NovaPay payment gateway is temporarily unavailable. Please try again shortly.'),
                $endpoint,
                $status,
                $this->panMasker->maskJson($responseBody)
            );
        }

        if ($status >= 400) {
            $this->logger->error('NovaPay API client error', [
                'event' => 'api.client_error',
                'endpoint' => $endpoint,
                'status' => $status,
                'body' => $this->panMasker->maskJson($responseBody),
            ]);

            throw new ClientErrorException(
                __('The NovaPay payment gateway rejected the request.'),
                $endpoint,
                $status,
                $this->panMasker->maskJson($responseBody)
            );
        }

        // A 2xx response with an empty or literal `null` body is a documented "no content" success
        // for several endpoints (e.g. /v1/void, /v1/complete-hold both document their 200 response
        // body as `null`/"" — see docs/novapay-api-reference.md and the cached OpenAPI specs) — it
        // must never be treated as an error. A caller that actually needs a field out of the
        // response (e.g. CheckoutOrchestrator reading /v1/session's `id` or /v1/payment's `url`)
        // already validates that field's presence itself and fails loudly with its own message when
        // it is missing, so returning an empty array here is safe for every call site. Only an exact
        // empty/whitespace-only body or the exact JSON literal `null` qualifies — anything else that
        // fails to decode (garbage, truncated JSON, ...) still falls through to the unparsable-body
        // error below. Gated to 200-299 only: Magento's Curl client does not follow redirects, so a
        // 1xx/3xx response (never actually expected from this API, but not explicitly rejected by
        // the guards above either) must never be silently treated as a content-less success — it
        // falls through to the unparsable-body error below instead, same as any other unexpected
        // status/body combination.
        if ($status >= 200 && $status < 300) {
            $trimmedBody = trim($responseBody);
            if ($trimmedBody === '' || $trimmedBody === 'null') {
                return [];
            }
        }

        $decoded = json_decode($responseBody, true);

        if (!is_array($decoded)) {
            $this->logger->critical('NovaPay API returned an unparsable response body', [
                'event' => 'api.unparsable_response',
                'endpoint' => $endpoint,
                'status' => $status,
            ]);

            throw new ServerErrorException(
                __('The NovaPay payment gateway returned an unexpected response.'),
                $endpoint,
                $status,
                $this->panMasker->maskJson($responseBody)
            );
        }

        // Defense in depth: NovaPay's own documented error-body shape (`{uuid, type: "processing"|
        // "validation", error|errors, code}` — see docs/novapay-api-reference.md and the cached
        // OpenAPI specs' 400 examples) occasionally needs to be recognized even under an HTTP status
        // this module did not expect to carry it — treat any decoded body carrying BOTH `code` and
        // one of `error`/`errors`/`type` as a rejected request regardless of the 2xx/3xx status line,
        // routed through the same {@see ClientErrorException}/{@see \NovaPay\Payment\Gateway\ErrorMapper}
        // path as a real 4xx so the admin sees the mapped friendly message instead of a silently
        // "successful" empty response.
        $hasNovaPayErrorShape = isset($decoded['code'])
            && (isset($decoded['error']) || isset($decoded['errors']) || isset($decoded['type']));
        if ($hasNovaPayErrorShape) {
            $this->logger->error('NovaPay API returned an error body under a non-error HTTP status', [
                'event' => 'api.client_error',
                'endpoint' => $endpoint,
                'status' => $status,
                'body' => $this->panMasker->maskJson($responseBody),
            ]);

            throw new ClientErrorException(
                __('The NovaPay payment gateway rejected the request.'),
                $endpoint,
                $status,
                $this->panMasker->maskJson($responseBody)
            );
        }

        return $decoded;
    }

    /**
     * Single code path building the exact string that is both sent and signed. `JSON_THROW_ON_ERROR`
     * means an encoding failure always raises \JsonException — it cannot return a falsy/empty value
     * that would otherwise get silently signed/POSTed as an empty body, so there is no `=== ''` case
     * to guard here (a non-empty `array $payload` — every call site always passes at least
     * `merchant_id` — can never encode to the empty string on success either).
     *
     * @param array $payload
     * @return string
     * @throws \JsonException
     */
    private function encode(array $payload): string
    {
        return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * Test Mode vs live API base URL for the given store scope.
     *
     * @param int|null $storeId
     * @return string
     */
    private function getBaseUrl(?int $storeId): string
    {
        return $this->config->isTestMode($storeId) ? self::BASE_URL_TEST : self::BASE_URL_LIVE;
    }
}
