<?php
/**
 * Copyright © 2026 NovaPay. All rights reserved.
 * Licensed under the MIT License. See LICENSE for details.
 */
declare(strict_types=1);

namespace NovaPay\Payment\Gateway\Validator;

use Magento\Payment\Gateway\Validator\AbstractValidator;
use Magento\Payment\Gateway\Validator\ResultInterface;

/**
 * Native Magento availability guard: NovaPay is UAH-only, rejected via the standard `currency` slot
 * of the gateway adapter's `validatorPool` — the same mechanism core gateway modules use. Wired into
 * {@see \NovaPay\Payment\Model\Adapter} in `etc/di.xml`.
 *
 * `Magento\Payment\Model\Method\Adapter::canUseForCurrency()` calls `validatorPool->get('currency')`
 * during checkout, so a non-UAH base-currency store hides the "NovaPay" radio button automatically,
 * without any custom code in the checkout layout/JS.
 *
 * This guard should be revisited if/when NovaPay adds conversion or multi-currency support.
 *
 * @api
 */
class CurrencyValidator extends AbstractValidator
{
    public const SUPPORTED_CURRENCY = 'UAH';

    /**
     * @inheritDoc
     */
    public function validate(array $validationSubject): ResultInterface
    {
        $currency = (string)($validationSubject['currency'] ?? '');

        return $this->createResult($currency === self::SUPPORTED_CURRENCY);
    }
}
