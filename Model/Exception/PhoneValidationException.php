<?php
/**
 * Copyright © 2026 NovaPay. All rights reserved.
 * Licensed under the MIT License. See LICENSE for details.
 */
declare(strict_types=1);

namespace NovaPay\Payment\Model\Exception;

/**
 * Thrown by {@see \NovaPay\Payment\Model\CheckoutOrchestrator} when phone validation is enabled
 * (payment/novapay/phone_validation_enabled) and the billing address telephone does not match the
 * Ukrainian +380 mask. A subtype of \InvalidArgumentException so it is still caught by any existing
 * `catch (\InvalidArgumentException)` handling, but distinct enough for
 * {@see \NovaPay\Payment\Controller\Redirect\Index} to show the exception's own (admin-configured,
 * customer-facing) message and send the shopper back to checkout instead of the generic
 * "could not redirect you to NovaPay" cart message used for every other failure.
 *
 * @api
 */
class PhoneValidationException extends \InvalidArgumentException
{
}
