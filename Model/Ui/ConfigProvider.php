<?php
/**
 * Copyright © 2026 NovaPay. All rights reserved.
 * Licensed under the MIT License. See LICENSE for details.
 */
declare(strict_types=1);

namespace NovaPay\Payment\Model\Ui;

use Magento\Checkout\Model\ConfigProviderInterface;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Asset\Repository as AssetRepository;
use NovaPay\Payment\Gateway\Config;

/**
 * Feeds `window.checkoutConfig.payment.novapay` for the checkout JS component: title, logo asset
 * URL, the redirect controller URL the JS navigates to after order placement instead of the
 * default checkout success page, and the phone-validation settings the payment renderer's
 * `validate()` mirrors against the billing address telephone before allowing order placement
 * ({@see \NovaPay\Payment\Model\PhoneNumberValidator} is the server-side source of truth for the
 * same rule).
 *
 * @api
 */
class ConfigProvider implements ConfigProviderInterface
{
    private const LOGO_ASSET = 'NovaPay_Payment::images/novapay-logo.svg';

    /**
     * @param Config $config
     * @param AssetRepository $assetRepository
     * @param UrlInterface $urlBuilder
     */
    public function __construct(
        private readonly Config $config,
        private readonly AssetRepository $assetRepository,
        private readonly UrlInterface $urlBuilder
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getConfig(): array
    {
        return [
            'payment' => [
                Config::CODE => [
                    'title' => $this->config->getTitle(),
                    'logoUrl' => $this->assetRepository->getUrl(self::LOGO_ASSET),
                    'redirectUrl' => $this->urlBuilder->getUrl('novapay/redirect/index', ['_secure' => true]),
                    'phoneValidationEnabled' => $this->config->isPhoneValidationEnabled(),
                    'phoneValidationErrorMessage' => (string)__($this->config->getPhoneValidationErrorMessage()),
                ],
            ],
        ];
    }
}
