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

namespace Novapay\Payment\Block\Adminhtml\System\Config\Fieldset;

use Novapay\Payment\Block\Adminhtml\System\Config\Fieldset\Base\Payment;

/**
 * Renderer for Novapay Checkout Panel in System Configuration
 *
 * Class CheckoutPayment
 * @package Novapay\Payment\Block\Adminhtml\System\Config\Fieldset
 */
class CheckoutPayment extends Payment
{
    /**
     * Retrieves the Module Panel Css Class
     * @return string
     */
    protected function getBlockHeadCssClass()
    {
        return "NovapayCheckout";
    }
}
