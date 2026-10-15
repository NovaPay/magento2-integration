<?php
/**
 * Copyright © 2026 NovaPay. All rights reserved.
 * Licensed under the MIT License. See LICENSE for details.
 */
declare(strict_types=1);

namespace NovaPay\Payment\Model\Postback;

use Psr\Log\LoggerInterface;

/**
 * Base for postback schema versions the module declares (v1/v2/v3) but does not yet implement.
 * Never silently "succeeds" — every call is logged as a not-implemented warning and returns null,
 * which {@see PostbackProcessor} treats like any other unparsable payload (log, stop, still HTTP
 * 200). A merchant whose NovaPay admin panel is set to postback version v1/v2 must switch to v3
 * (default) until a real parser lands.
 */
abstract class UnimplementedParser implements PostbackParserInterface
{
    /**
     * @param LoggerInterface $logger
     * @param string $version e.g. "v1", "v2" — used only for the log message
     */
    public function __construct(private readonly LoggerInterface $logger, private readonly string $version)
    {
    }

    /**
     * @inheritDoc
     */
    public function parse(array $decoded): ?ParsedPostback
    {
        $this->logger->warning(
            sprintf(
                'NovaPay postback parser for version "%s" is not implemented; the payload was not processed.'
                . ' Switch "Postback Version" to v3 in your NovaPay merchant admin and in module config.',
                $this->version
            ),
            ['event' => 'postback.malformed', 'reason' => 'unimplemented_version', 'version' => $this->version]
        );

        return null;
    }
}
