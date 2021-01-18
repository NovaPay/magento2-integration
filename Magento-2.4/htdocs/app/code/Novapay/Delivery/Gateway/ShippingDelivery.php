<?php
/**
 * ShippingDelivery structure class.
 * 
 * PHP version 7.X
 * 
 * @category Module
 * @package  Magento2
 * @author   NovaPay <acquiring@novapay.ua>
 * @license  https://github.com/NovaPay/prestashop-integration/blob/master/LICENSE MIT
 * @link     https://novapay.ua/
 */

namespace Novapay\Delivery\Gateway;

use Novapay\Payment\SDK\Schema\Delivery;

/**
 * ShippingDelivery structure class.
 * 
 * @category Module
 * @package  Magento2
 * @author   NovaPay <acquiring@novapay.ua>
 * @license  https://github.com/NovaPay/prestashop-integration/blob/master/LICENSE MIT
 * @link     https://novapay.ua/
 */
class ShippingDelivery
{
    public $delivery;
    public $total;

    /**
     * ShippingDelivery constructor.
     *
     * @param Delivery $delivery Deliverry object with {weight, volume, city, warehouse}
     * @param float    $total    Order subtotal amount
     */
    public function __construct(Delivery $delivery, $total)
    {
        $this->delivery = $delivery;
        $this->total    = $total;
    }
}