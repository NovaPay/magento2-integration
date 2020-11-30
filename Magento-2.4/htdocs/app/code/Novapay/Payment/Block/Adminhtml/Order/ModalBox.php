<?php
/**
 * ModalBox template for confirm hold amount dialog.
 * 
 * PHP version 7.X
 * 
 * @category Module
 * @package  Magento2
 * @author   NovaPay <acquiring@novapay.ua>
 * @license  https://github.com/NovaPay/prestashop-integration/blob/master/LICENSE MIT
 * @link     https://novapay.ua/
 */

namespace Novapay\Payment\Block\Adminhtml\Order;

use Magento\Backend\Block\Template;

/**
 * ModalBox template for confirm hold amount dialog.
 *
 * @package Novapay\Payment\Block\Adminhtml\Order
 */
class ModalBox extends Template
{

    /**
     * Constructor
     *
     * @param \Magento\Backend\Block\Template\Context  $context
     * @param array $data
     */
    public function __construct(
        \Magento\Backend\Block\Template\Context $context,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * @return string
     */
    public function getInfo()
    {
        return __('Confirm hold amount of the current order');
    }

    /**
     * @return string URL for current order.
     */
    public function getFormUrl()
    {
        $orderId = false;
        if ($this->hasData('order')){
            $orderId = $this->getOrder()->getId();
        }
        return $this->getUrl('novapay/order/order',[
            'order_id' => $orderId
        ]);
    }
}

