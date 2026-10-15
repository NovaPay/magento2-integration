<?php
/**
 * Copyright © 2026 NovaPay. All rights reserved.
 * Licensed under the MIT License. See LICENSE for details.
 */
declare(strict_types=1);

namespace NovaPay\Payment\Gateway\Http\Exception;

/**
 * No HTTP response was obtained at all: connect timeout, total timeout, DNS/TLS failure, or any other
 * transport-level curl error. {@see getHttpStatusCode()} is always null for this type.
 *
 * @api
 */
class TransportException extends ApiException
{
}
