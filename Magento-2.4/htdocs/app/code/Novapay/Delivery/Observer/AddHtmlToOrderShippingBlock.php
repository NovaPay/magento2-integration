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
        // there is no frontend layout implementation due to the project scope
        return $this;
    }
}