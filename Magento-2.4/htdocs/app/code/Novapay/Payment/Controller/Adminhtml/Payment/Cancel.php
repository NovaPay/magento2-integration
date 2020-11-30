<?php
/**
 * Update status controller class.
 * 
 * PHP version 7.X
 * 
 * @category Module
 * @package  Magento2
 * @author   NovaPay <acquiring@novapay.ua>
 * @license  https://github.com/NovaPay/prestashop-integration/blob/master/LICENSE MIT
 * @link     https://novapay.ua/
 */

// http://magento-dev.sprinterra.com/index.php/admin/novapay/payment/status/
// http://magento-dev.sprinterra.com/index.php/admin/novapay/payment/payment/status/

namespace Novapay\Payment\Controller\Adminhtml\Payment;
 
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\ResultFactory;
 
class Status extends Action
{
    protected $resultFactory;
 
    public function __construct(
        Context $context,
        ResultFactory $resultFactory
    )
    {
        parent::__construct($context);
        $this->resultFactory = $resultFactory;
    }
 
    public function execute()
    {
        // echo 'My Backend Controller Works!';
        $result = $this->resultFactory->create(ResultFactory::TYPE_JSON);
        $result->setData(['hello' => 'mister']);
        return $result;
    }
}