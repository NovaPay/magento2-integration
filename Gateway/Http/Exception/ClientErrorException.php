<?php
/**
 * Copyright © 2026 NovaPay. All rights reserved.
 * Licensed under the MIT License. See LICENSE for details.
 */
declare(strict_types=1);

namespace NovaPay\Payment\Gateway\Http\Exception;

/**
 * NovaPay responded with a 4xx status (bad request, validation error, acquiring error — see
 * https://novapay.readme.io/reference/acquiring-errors). Order/session state is left unchanged by
 * the caller; the error is logged with the response body.
 *
 * @api
 */
class ClientErrorException extends ApiException
{
}
