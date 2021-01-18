<?php
namespace Novapay\Delivery\Observer;

use Magento\Framework\Event\Observer as EventObserver;
use Magento\Framework\Event\ObserverInterface;

class AdminhtmlSalesOrderCreateProcessData implements ObserverInterface
{
    /**
     * @param EventObserver $observer
     * @return $this
     */
    public function execute(EventObserver $observer)
    {
        $requestData = $observer->getRequest();
        $cityRef = $requestData['city_ref'] ?? null;
        $warehouseRef = $requestData['warehouse_ref'] ?? null;
        // $deliveryDate = isset($requestData['delivery_date']) ? $requestData['delivery_date'] : null;
        // $deliveryComment = isset($requestData['delivery_comment']) ? $requestData['delivery_comment'] : null;

        /** @var \Magento\Sales\Model\AdminOrder\Create $orderCreateModel */
        $orderCreateModel = $observer->getOrderCreateModel();
        $quote = $orderCreateModel->getQuote();
        $quote->setCityRef($cityRef);
        $quote->setWarehouseRef($warehouseRef);
        // $quote->setDeliveryDate($deliveryDate);
        // $quote->setDeliveryComment($deliveryComment);

        return $this;
    }
}