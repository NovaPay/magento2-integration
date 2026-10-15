<?php
/**
 * Copyright © 2026 NovaPay. All rights reserved.
 * Licensed under the MIT License. See LICENSE for details.
 */
declare(strict_types=1);

namespace NovaPay\Payment\Gateway\Exception;

use Magento\Framework\Exception\LocalizedException;

/**
 * Thrown by {@see \NovaPay\Payment\Gateway\SignatureService} when a configured RSA key is missing,
 * malformed, or otherwise rejected by openssl (e.g. openssl_pkey_get_private()/openssl_pkey_get_public()
 * returning false). Never swallow this into a blank/garbage signature.
 *
 * @api
 */
class InvalidKeyException extends LocalizedException
{
}
