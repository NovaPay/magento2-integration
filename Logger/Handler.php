<?php
/**
 * Copyright © 2026 NovaPay. All rights reserved.
 * Licensed under the MIT License. See LICENSE for details.
 */
declare(strict_types=1);

namespace NovaPay\Payment\Logger;

use Magento\Framework\Logger\Handler\Base;
use Monolog\Formatter\JsonFormatter;

/**
 * Dedicated NovaPay log stream: every module log line goes to `var/log/novapay.log` as one
 * JSON object per line, never into Magento's `system.log`/`debug.log`. The file path is wired via
 * this module's `etc/di.xml` `fileName` argument on this type, so it can be relocated without
 * touching this class.
 *
 * {@see Base} unconditionally installs a `LineFormatter` in its constructor with no DI hook to
 * change it; this subclass exists solely to swap in the {@see JsonFormatter} afterwards. Level
 * filtering when "Enable Logging" is off is the calling code's job; WARNING+ is always written.
 *
 * @api
 */
class Handler extends Base
{
    /**
     * @inheritDoc
     */
    public function __construct(
        \Magento\Framework\Filesystem\DriverInterface $filesystem,
        ?string $filePath = null,
        ?string $fileName = null
    ) {
        parent::__construct($filesystem, $filePath, $fileName);

        $this->setFormatter(new JsonFormatter());
    }
}
