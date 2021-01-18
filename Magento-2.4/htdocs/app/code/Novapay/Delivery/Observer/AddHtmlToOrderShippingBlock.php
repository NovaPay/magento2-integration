<?php
namespace Novapay\Delivery\Observer;

use Magento\Framework\Event\Observer as EventObserver;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\View\Element\TemplateFactory;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;

class AddHtmlToOrderShippingBlock implements ObserverInterface
{
    /**
     * @var TemplateFactory
     */
    protected $templateFactory;

    /**
     * AddHtmlToOrderShippingBlock constructor.
     *
     * @param TemplateFactory $templateFactory
     * @param TimezoneInterface $timezone
     */
    public function __construct(
        TemplateFactory $templateFactory
    ) {
        $this->templateFactory = $templateFactory;
    }

    /**
     * Returns the place (element) name where block must be added.
     * 
     * @return string
     */
    protected function getPlaceName()
    {
        return 'sales.order.info';
    }

    /**
     * @param EventObserver $observer
     * @return $this
     */
    public function execute(EventObserver $observer)
    {
        if ($observer->getElementName() != $this->getPlaceName()) {
            return $this;
        }
        $orderShippingViewBlock = $observer->getLayout()->getBlock($observer->getElementName());
        $order = $orderShippingViewBlock->getOrder();

        /** @var \Magento\Framework\View\Element\Template $deliveryDateBlock */
        $warehouseBlock = $this->templateFactory->create();
        $warehouseBlock->setCityRef($order->getCityRef());
        $warehouseBlock->setCityTitle($order->getCityTitle());
        $warehouseBlock->setWarehouseRef($order->getWarehouseRef());
        $warehouseBlock->setWarehouseTitle($order->getWarehouseTitle());
        $warehouseBlock->setTemplate('Novapay_Delivery::order_info_shipping_info.phtml');
        $html = $observer->getTransport()->getOutput() . $warehouseBlock->toHtml();
        $observer->getTransport()->setOutput($html);

        return $this;
    }
}