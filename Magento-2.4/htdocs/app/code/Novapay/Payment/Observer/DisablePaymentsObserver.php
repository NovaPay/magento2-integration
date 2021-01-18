<?php

namespace Novapay\Delivery\Observer;

use Magento\Framework\Event\Observer;

class DisablePaymentsObserver {

    public function paymentMethodIsActive(Observer $observer)
    {
        $method = $observer->getMethodInstance();
        
        if ($method->getCode() != 'novapay_payment') {
            $result = $observer->getResult();
            $result->isAvailable = false;
        }
    }
}