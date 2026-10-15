<?php
/**
 * Copyright © 2026 NovaPay. All rights reserved.
 * Licensed under the MIT License. See LICENSE for details.
 */
declare(strict_types=1);

namespace NovaPay\Payment\Model\Order;

use Magento\Framework\App\Area;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\State as AppState;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Locale\Resolver as LocaleResolver;
use Magento\Framework\Phrase;
use Magento\Framework\TranslateInterface;

/**
 * Renders a module phrase in the admin interface language, for order status history comments.
 *
 * Postbacks and the get-status cron run without an admin user, in a storefront or cron context
 * whose locale (e.g. the store view's uk_UA) is not the admin's. The admin uses the default-scope
 * `general/locale/code` for a user without a personal interface locale (see
 * {@see \Magento\Backend\Model\Locale\Resolver} and {@see LocaleResolver::getDefaultLocale()}), so
 * that locale is used here. When it differs from the translator's current locale, the shared
 * translator is switched to it with the adminhtml area, the phrase is rendered, and the previous
 * locale and area are restored in a `finally` block - the same mechanism as
 * {@see \Magento\Store\Model\App\Emulation}.
 */
class AdminPhraseRenderer
{
    private const XML_PATH_DEFAULT_LOCALE = 'general/locale/code';

    /**
     * @param TranslateInterface $translate
     * @param ScopeConfigInterface $scopeConfig
     * @param AppState $appState
     */
    public function __construct(
        private readonly TranslateInterface $translate,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly AppState $appState
    ) {
    }

    /**
     * Render `$phrase` translated into the admin default locale.
     *
     * @param Phrase $phrase
     * @return string
     */
    public function render(Phrase $phrase): string
    {
        $adminLocale = $this->getAdminLocale();
        $currentLocale = (string)$this->translate->getLocale();

        if ($adminLocale === $currentLocale) {
            return $phrase->render();
        }

        try {
            $this->translate->setLocale($adminLocale)->loadData(Area::AREA_ADMINHTML, true);

            return $phrase->render();
        } finally {
            $this->translate->setLocale($currentLocale)->loadData($this->getCurrentArea());
        }
    }

    /**
     * The default-scope interface locale, falling back to en_US like the core resolver.
     *
     * @return string
     */
    private function getAdminLocale(): string
    {
        $locale = $this->scopeConfig->getValue(
            self::XML_PATH_DEFAULT_LOCALE,
            ScopeConfigInterface::SCOPE_TYPE_DEFAULT
        );

        return is_string($locale) && $locale !== '' ? $locale : LocaleResolver::DEFAULT_LOCALE;
    }

    /**
     * The area the translator is restored for: the running area, or global if none is set.
     *
     * @return string
     */
    private function getCurrentArea(): string
    {
        try {
            return $this->appState->getAreaCode();
        } catch (LocalizedException) {
            return Area::AREA_GLOBAL;
        }
    }
}
