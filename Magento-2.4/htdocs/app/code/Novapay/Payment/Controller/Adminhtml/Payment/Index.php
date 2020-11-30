<?php

// http://magento-dev.sprinterra.com/index.php/admin/novapay/payment/index/index

namespace Novapay\Payment\Controller\Adminhtml\Payment;
 
use Magento\Backend\App\Action;
use Magento\Framework\Controller\ResultFactory;
 
class Index extends Action
{
    protected $resultPageFactory;
 
    public function execute()
    {
        $result = $this->resultFactory->create(ResultFactory::TYPE_JSON);
        $result->setData(['hello' => 'mister', 'class' => static::class]);
        return $result;
    }
}