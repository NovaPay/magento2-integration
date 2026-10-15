<?php
/**
 * Copyright © 2026 NovaPay. All rights reserved.
 * Licensed under the MIT License. See LICENSE for details.
 */
declare(strict_types=1);

namespace NovaPay\Payment\Model\Postback;

use Psr\Log\LoggerInterface;

/**
 * v1 postback schema — not implemented (see {@see UnimplementedParser}).
 *
 * @api
 */
class V1Parser extends UnimplementedParser
{
    /**
     * @param LoggerInterface $logger
     */
    public function __construct(LoggerInterface $logger)
    {
        parent::__construct($logger, 'v1');
    }
}
