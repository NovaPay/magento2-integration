<?php
/**
 * Gateway Config class.
 * Used for getting configuration saved in Admin UI.
 * 
 * PHP version 7.X
 * 
 * @category Module
 * @package  Magento2
 * @author   NovaPay <acquiring@novapay.ua>
 * @license  https://github.com/NovaPay/prestashop-integration/blob/master/LICENSE MIT
 * @link     https://novapay.ua/
 */

namespace Novapay\Payment\Gateway;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Gateway Config class.
 * 
 * @category Module
 * @package  Magento2
 * @author   NovaPay <acquiring@novapay.ua>
 * @license  https://github.com/NovaPay/prestashop-integration/blob/master/LICENSE MIT
 * @link     https://novapay.ua/
 */
class Config
{
    protected $scopeConfig;

    /**
     * AbsctractCheckoutAction constructor.
     * 
     * @param Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig Scope config.
     */
    public function __construct(
        ScopeConfigInterface $scopeConfig
    ) {
        $this->scopeConfig        = $scopeConfig;
    }

    /**
     * Returns payment config value by it's name.
     * 
     * @param string $name Config name
     * 
     * @return mixed       Config value.
     */
    public function getPaymentConfig($name)
    {
        return $this->scopeConfig->getValue(
            "payment/novapayment_gateway/$name",
            ScopeInterface::SCOPE_STORE
        );
    }

    /**
     * Returns order status value by it's payment status.
     * Stores > Configuration > Sales > Payment methods > Novapay > Status Mapping
     * 
     * @param string $status Payment status name.
     * 
     * @return string        Order status.
     */
    public function getPaymentStatus($status)
    {
        return $this->getPaymentConfig("status_$status");
    }
}
