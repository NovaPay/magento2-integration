<?php
/**
 * Copyright © 2026 NovaPay. All rights reserved.
 * Licensed under the MIT License. See LICENSE for details.
 */
declare(strict_types=1);

namespace NovaPay\Payment\Block\Adminhtml\System\Config\Form;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Framework\UrlInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\Store;

/**
 * Read-only admin field showing the fully resolved postback URL for the scope being edited.
 *
 * The value is built from the store's secure link base URL, which is the same base that
 * CheckoutOrchestrator uses when it sends `callback_url` (frontend `getUrl()` with `_secure`),
 * so the merchant can copy the exact string into the NovaPay merchant panel. Nothing is saved:
 * the rendered input has no `name` attribute and the field has no backend model.
 *
 * @api
 */
class PostbackUrl extends Field
{
    /**
     * Route path of the postback controller, with the trailing slash frontend getUrl() produces.
     */
    public const POSTBACK_PATH = 'novapay/postback/index/';

    /**
     * Render a read-only text input holding the resolved URL.
     *
     * @param AbstractElement $element
     * @return string
     */
    protected function _getElementHtml(AbstractElement $element): string
    {
        $url = $this->getPostbackUrl();

        return '<input type="text" readonly="readonly" class="admin__control-text novapay-postback-url"'
            . ' id="' . $this->_escaper->escapeHtmlAttr($element->getHtmlId()) . '"'
            . ' value="' . $this->_escaper->escapeHtmlAttr($url) . '"'
            . ' title="' . $this->_escaper->escapeHtmlAttr($url) . '" />';
    }

    /**
     * The value is derived, not stored, so there is nothing to inherit from a parent scope.
     *
     * @param AbstractElement $element
     * @return bool
     */
    protected function _isInheritCheckboxRequired(AbstractElement $element): bool
    {
        return false;
    }

    /**
     * Resolve the postback URL for the store behind the scope the config form is showing.
     *
     * @return string
     */
    public function getPostbackUrl(): string
    {
        $store = $this->resolveStore();
        $baseUrl = $store instanceof Store
            ? (string)$store->getBaseUrl(UrlInterface::URL_TYPE_LINK, true)
            : '';

        return $baseUrl . self::POSTBACK_PATH;
    }

    /**
     * Resolve the store whose base URL the field shows.
     *
     * Store scope: that store. Website scope: the website's default store. Default scope: the
     * default store view of the default website. Mirrors the request params core's config Form reads.
     *
     * @return StoreInterface
     */
    private function resolveStore(): StoreInterface
    {
        $storeId = (string)$this->getRequest()->getParam('store', '');
        if ($storeId !== '') {
            return $this->_storeManager->getStore($storeId);
        }

        $websiteId = (string)$this->getRequest()->getParam('website', '');
        if ($websiteId !== '') {
            $website = $this->_storeManager->getWebsite($websiteId);
            $defaultStoreId = (int)$this->_storeManager->getGroup((string)$website->getDefaultGroupId())
                ->getDefaultStoreId();
            if ($defaultStoreId > 0) {
                return $this->_storeManager->getStore($defaultStoreId);
            }
        }

        return $this->_storeManager->getDefaultStoreView() ?? $this->_storeManager->getStore();
    }
}
