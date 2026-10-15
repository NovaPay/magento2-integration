<?php
/**
 * Copyright © 2026 NovaPay. All rights reserved.
 * Licensed under the MIT License. See LICENSE for details.
 */
declare(strict_types=1);

namespace NovaPay\Payment\Model\Postback;

/**
 * Strategy for turning a decoded (already signature-verified) NovaPay postback body into a
 * {@see ParsedPostback}. One implementation per schema version (v1/v2/v3); selected by
 * {@see ParserPool} from the admin-configured "Postback Version" setting
 * ({@see \NovaPay\Payment\Gateway\Config::getPostbackVersion()}).
 *
 * Implementations must never throw for a malformed-but-well-formed-JSON payload — return null and
 * log instead ({@see PostbackProcessor} treats null as "could not parse, stop, still 200").
 *
 * @api
 */
interface PostbackParserInterface
{
    /**
     * Parse a decoded postback body into a {@see ParsedPostback}, or null if it cannot be parsed.
     *
     * @param array $decoded the full decoded JSON postback body (already signature-verified)
     * @return ParsedPostback|null
     */
    public function parse(array $decoded): ?ParsedPostback;
}
