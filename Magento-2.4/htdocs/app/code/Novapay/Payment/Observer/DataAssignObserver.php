<?php
/**
 * DataAssignObserver attached to events sush as
 *  payment_method_assign_data.
 * 
 * PHP version 7.X
 * 
 * @category Module
 * @package  Magento2
 * @author   NovaPay <acquiring@novapay.ua>
 * @license  https://github.com/NovaPay/prestashop-integration/blob/master/LICENSE MIT
 * @link     https://novapay.ua/
 */
namespace Novapay\Payment\Observer;

use Magento\Framework\Event\Observer;
use Magento\Payment\Observer\AbstractDataAssignObserver;

/**
 * DataAssignObserver attached to events sush as
 *  payment_method_assign_data.
 */
class DataAssignObserver extends AbstractDataAssignObserver
{
    /**
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer)
    {
        $method      = $this->readMethodArgument($observer);
        $data        = $this->readDataArgument($observer);

        $paymentInfo = $method->getInfoInstance();

        // @todo remove
        if ($data->getDataByKey('transaction_result') !== null) {
            $paymentInfo->setAdditionalInformation(
                'transaction_result',
                $data->getDataByKey('transaction_result')
            );
        }
    }
}
