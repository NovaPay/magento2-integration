<?php
/**
 * Renderer for Checkout Panel in System Configuration
 * 
 * PHP version 7.X
 * 
 * @category Module
 * @package  Magento2
 * @author   NovaPay <acquiring@novapay.ua>
 * @license  https://github.com/NovaPay/prestashop-integration/blob/master/LICENSE MIT
 * @link     https://novapay.ua/
 */

namespace Novapay\Payment\Block\Adminhtml\System\Config\Fieldset\Base;

use Magento\Config\Block\System\Config\Form\Fieldset;

/**
 * Base Renderer for Novapay Payment Fieldset
 *
 * Class Payment
 * @package Novapay\Payment\Block\Adminhtml\System\Config\Fieldset\Base
 */
abstract class Payment extends Fieldset
{
    /**
     * @var \Magento\Config\Model\Config
     */
    protected $_backendConfig;

    /**
     * @param \Magento\Backend\Block\Context $context
     * @param \Magento\Backend\Model\Auth\Session $authSession
     * @param \Magento\Framework\View\Helper\Js $jsHelper
     * @param \Magento\Config\Model\Config $backendConfig
     * @param array $data
     */
    public function __construct(
        \Magento\Backend\Block\Context $context,
        \Magento\Backend\Model\Auth\Session $authSession,
        \Magento\Framework\View\Helper\Js $jsHelper,
        \Magento\Config\Model\Config $backendConfig,
        array $data = []
    ) {
        $this->_backendConfig = $backendConfig;
        parent::__construct($context, $authSession, $jsHelper, $data);
    }

    /**
     * Retrieves the Module Panel Css Class
     * @return string
     */
    abstract protected function getBlockHeadCssClass();

    /**
     * Add custom css class
     *
     * @param \Magento\Framework\Data\Form\Element\AbstractElement $element
     * @return string
     */
    protected function _getFrontendClass($element)
    {
        return parent::_getFrontendClass($element) . ' with-button';
    }


    /**
     * Return header comment part of html for payment solution
     *
     * @param \Magento\Framework\Data\Form\Element\AbstractElement $element
     * @return string
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    protected function _getHeaderCommentHtml($element)
    {
        return '';
    }

    /**
     * Get collapsed state on-load
     *
     * @param \Magento\Framework\Data\Form\Element\AbstractElement $element
     * @return bool
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    protected function _isCollapseState($element)
    {
        return false;
    }

    /**
     * @param \Magento\Framework\Data\Form\Element\AbstractElement $element
     * @return string
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    protected function _getExtraJs($element)
    {
        $script = "require(['jquery', 'prototype'], function(jQuery){
            window.novapayToggleSolution = function (id, url) {
                Fieldset.toggleCollapse(id, url);
            }
        });";

        return $this->_jsHelper->getScript($script);
    }
}
