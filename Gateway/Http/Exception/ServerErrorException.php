<?php
/**
 * Copyright © 2026 NovaPay. All rights reserved.
 * Licensed under the MIT License. See LICENSE for details.
 */
declare(strict_types=1);

namespace NovaPay\Payment\Gateway\Http\Exception;

/**
 * NovaPay responded with a 5xx status, or a 2xx response whose body could not be parsed as the
 * expected JSON object. Treated as a transient failure by callers.
 *
 * @api
 */
class ServerErrorException extends ApiException
{
}
