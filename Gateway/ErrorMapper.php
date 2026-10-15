<?php
/**
 * Copyright © 2026 NovaPay. All rights reserved.
 * Licensed under the MIT License. See LICENSE for details.
 */
declare(strict_types=1);

namespace NovaPay\Payment\Gateway;

use NovaPay\Payment\Gateway\Http\Exception\ApiException;
use NovaPay\Payment\Gateway\Http\Exception\TransportException;

/**
 * Maps a NovaPay API error response to a friendly, translated {@see GatewayRejectedException} for
 * admin-facing capture/void/refund operations.
 *
 * NovaPay's error responses carry a machine-readable `code` field and a response `uuid`. The
 * `/v1/void` codes and their Ukrainian texts come from NovaPay's official list of refund errors
 * (docs/novapay-void-errors.md): the Ukrainian text is the uk_UA translation of the English source
 * string in {@see MESSAGES}. Two of those "codes" are not identifiers (`1c service request failed`,
 * `1c service bad response`) and are matched by exact string. The HTTP 500 response has no code at
 * all; it is recognized by its HTTP status (any 5xx) or by its `error` text. An unknown code gets a
 * generic message that still names the code. When NovaPay returned a `uuid`, the admin message ends
 * with " (NovaPay ref: <uuid>)" so NovaPay support can find the request.
 *
 * This class's job is purely translation, not logging of the raw response body: `$exception` is
 * kept as the returned exception's `$cause`, and the body itself was already logged (PAN-masked) at
 * the point of failure by {@see \NovaPay\Payment\Gateway\Http\Client::handleResponse()}.
 *
 * {@see TransportException} (no HTTP response at all: timeout/DNS/TLS) is a distinct case:
 * "gateway rejected the request" would misrepresent a connectivity failure as a business decline,
 * so {@see map()} keeps the exception's own phrase for that type, without a code or uuid.
 *
 * The same mapper serves `/v1/complete-hold` (capture) and the hold release (`/v1/void` without
 * operations); the codes those calls share with refunds use the same texts.
 *
 * Deliberately excludes the numeric card-processing `resultCode` table (100-900): that table
 * describes the *card issuer's* decline reason as echoed in a postback's `processing_result`, not
 * the synchronous `code` this module's own complete-hold/void API calls fail with.
 *
 * @api
 */
class ErrorMapper
{
    /**
     * NovaPay `code` → friendly, translatable message.
     */
    private const MESSAGES = [
        // NovaPay's official /v1/void (refund) error list
        'NotFoundError' => 'The data required for the refund could not be found. Check the identifiers in '
            . 'the request. If the data is correct, contact NovaPay support and provide the order details.',
        'SessionNotFoundError' => 'The payment session is not available for a refund. Contact NovaPay '
            . 'support and provide the order details.',
        'SessionAlreadyRefundedError' => 'The refund cannot be made. A refund of the session has already '
            . 'been registered.',
        'OperationAlreadyRefundedError' => 'A refund has already been registered for the specified '
            . 'operation. Check its current status before sending the request again.',
        'RefundError' => 'The refund cannot be made with the parameters provided. Contact NovaPay support '
            . 'for clarification.',
        'RefundTryLaterError' => 'The operation is temporarily not ready for a refund. Try the request '
            . 'again later.',
        'UnsupportedProductError' => 'A refund through this method is not available for the specified '
            . 'operation. Contact NovaPay support to find out which refund method is available.',
        'RefundPeriodExpiredError' => 'The allowed refund period has expired. Contact NovaPay support to '
            . 'find out what to do next.',
        'WrongAmountError' => 'An invalid refund amount was sent. Check the amount of the operation that '
            . 'is available for a refund.',
        'ContragentMissingTreatyIdError' => 'The refund is not available because of the recipient '
            . 'settings. Contact NovaPay support and provide the order details.',
        'UnknownPaymentError' => 'The refund could not be processed. Try the request again later. If the '
            . 'error persists, contact NovaPay support and provide the order details.',
        'WrongNovapayCardSessionStatusError' => 'The refund cannot be made in the current status of the '
            . 'operation. Check the payment status and try the request again later.',
        'UnknownNovaclientsError' => 'The refund could not be processed because of a temporary technical '
            . 'error. Try the request again later or contact NovaPay support.',
        'UnknownSignerError' => 'The refund could not be finalized. Try the request again later. If the '
            . 'error persists, contact NovaPay support.',
        '1c service request failed' => 'The refund could not be finalized because of a temporary technical '
            . 'error. Try the request again later.',
        '1c service bad response' => 'The refund could not be finalized. Try the request again later or '
            . 'contact NovaPay support.',
        'DataIntegrityError' => 'The refund could not be processed because of a data error. Contact '
            . 'NovaPay support and provide the order details.',
        'QueryError' => 'The refund could not be processed. Check the request parameters. If they are '
            . 'correct, contact NovaPay support and provide the order details.',
        'MerchantAccessDenied' => 'The request could not be authorized. Check the merchant ID and the '
            . 'authorization credentials.',
        'InvalidSignError' => 'The request signature could not be verified. Check the signature algorithm '
            . 'and/or the key.',
        'IpAddressNotRegisteredError' => 'The request was sent from an IP address that is not allowed. Use '
            . 'a registered address or contact NovaPay to update the settings.',
        // Other NovaPay codes (not in the refund list)
        'SystemError' => 'A NovaPay system error occurred. Please try again shortly.',
        'PublicQueryError' => 'NovaPay rejected the request: invalid data.',
        'CorruptedRequestError' => 'NovaPay rejected the request as malformed.',
        'HoldMixError' => 'This NovaPay hold cannot be mixed with a different operation type on the same session.',
        'ContragentNotFoundError' => 'NovaPay could not find the specified recipient (ЄДРПОУ/ІПН).',
        'WrongSessionStatusError' => 'This NovaPay session is not in a state that allows this operation.',
        'Fatal' => 'A NovaPay system error occurred. Please try again shortly.',
    ];

    /**
     * NovaPay's HTTP 500 response: no `code`, only this `error` text.
     */
    private const SYSTEM_ERROR_TEXT = 'System error. Please try again later or contact support.';

    /**
     * Message for NovaPay's HTTP 500 response (no `code`).
     */
    private const SERVER_ERROR_MESSAGE = 'The refund could not be processed because of an internal error. '
        . 'Try the request again later. If the error persists, contact NovaPay support and provide the '
        . 'order details.';

    /**
     * Map a failed complete-hold/void call to a friendly, translated exception.
     *
     * @param ApiException $exception a failed complete-hold/void call ({@see ClientErrorException}
     *     most commonly, {@see \NovaPay\Payment\Gateway\Http\Exception\ServerErrorException} for a 5xx,
     *     or {@see TransportException} when the gateway could not be reached at all)
     * @return GatewayRejectedException friendly message for the admin; `$exception` kept as the cause
     *     for logging (never rendered to the admin itself)
     */
    public function map(ApiException $exception): GatewayRejectedException
    {
        if ($exception instanceof TransportException) {
            // "Gateway unreachable" is not "gateway rejected": keep its own already-translated phrase
            // (re-wrapped in __() only because LocalizedException's constructor requires a Phrase).
            $reason = __($exception->getMessage());

            return new GatewayRejectedException(
                $reason,
                $reason,
                null,
                null,
                null,
                $exception->getEndpoint(),
                true,
                $exception
            );
        }

        $body = $this->decodeBody($exception->getResponseBody());
        $code = $this->readString($body, 'code');
        $uuid = $this->readString($body, 'uuid');
        $httpStatus = $exception->getHttpStatusCode();

        // The texts are listed in i18n/*.csv by hand: the phrase collector cannot read constants.
        $knownMessage = $code !== null ? (self::MESSAGES[$code] ?? null) : null;
        $serverErrorMessage = self::SERVER_ERROR_MESSAGE;
        if ($knownMessage !== null) {
            $reason = __($knownMessage);
        } elseif ($code !== null) {
            $reason = __('NovaPay rejected the request (code %1). Please check the logs for details.', $code);
        } elseif (($httpStatus !== null && $httpStatus >= 500)
            || $this->readString($body, 'error') === self::SYSTEM_ERROR_TEXT
        ) {
            $reason = __($serverErrorMessage);
        } else {
            $reason = __('The NovaPay payment gateway rejected the request. Please check the logs for details.');
        }

        if ($code === null && $httpStatus !== null) {
            $code = 'HTTP ' . $httpStatus;
        }

        $message = $uuid !== null ? __('%1 (NovaPay ref: %2)', $reason, $uuid) : $reason;

        return new GatewayRejectedException(
            $message,
            $reason,
            $code,
            $uuid,
            $httpStatus,
            $exception->getEndpoint(),
            false,
            $exception
        );
    }

    /**
     * Decode a NovaPay JSON error body, returning an empty array when it is missing or malformed.
     *
     * @param string|null $responseBody
     * @return array
     */
    private function decodeBody(?string $responseBody): array
    {
        if ($responseBody === null || trim($responseBody) === '') {
            return [];
        }

        try {
            $decoded = json_decode($responseBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * A non-empty string field of the decoded body, or null.
     *
     * @param array $body
     * @param string $field
     * @return string|null
     */
    private function readString(array $body, string $field): ?string
    {
        if (!isset($body[$field]) || !is_string($body[$field])) {
            return null;
        }

        $value = trim($body[$field]);

        return $value !== '' ? $value : null;
    }
}
