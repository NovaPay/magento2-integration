<?php
namespace Novapay\Delivery\Observer;

use Magento\Framework\Event\ObserverInterface;

class AddHtmlToOrderShippingView extends AddHtmlToOrderShippingBlock implements ObserverInterface
{
    /**
     * Returns the place (element) name where block must be added.
     * 
     * @return string
     */
    protected function getPlaceName()
    {
        return 'order_shipping_view';
    }
}