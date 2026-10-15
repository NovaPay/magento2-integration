<?php
/**
 * Copyright © 2026 NovaPay. All rights reserved.
 * Licensed under the MIT License. See LICENSE for details.
 */
declare(strict_types=1);

namespace NovaPay\Payment\Model\Postback;

/**
 * Version string ("v1"|"v2"|"v3") → {@see PostbackParserInterface} lookup, wired in etc/di.xml.
 *
 * @api
 */
class ParserPool
{
    /**
     * @param PostbackParserInterface[] $parsers keyed by version string
     */
    public function __construct(private readonly array $parsers)
    {
    }

    /**
     * The parser registered for `$version`, or null if none is.
     *
     * @param string $version
     * @return PostbackParserInterface|null
     */
    public function get(string $version): ?PostbackParserInterface
    {
        return $this->parsers[$version] ?? null;
    }
}
