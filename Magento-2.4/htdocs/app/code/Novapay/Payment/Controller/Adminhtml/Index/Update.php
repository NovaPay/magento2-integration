<?php
/**
 * Update order status admin controller class.
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

namespace Novapay\Payment\Controller\Adminhtml;
 
use Magento\Backend\App\Action;
use Magento\Framework\Controller\ResultFactory;
 
/**
 * Update order status admin controller class.
 * 
 * @category Module
 * @package  Magento2
 * @author   NovaPay <acquiring@novapay.ua>
 * @license  https://github.com/NovaPay/prestashop-integration/blob/master/LICENSE MIT
 * @link     https://novapay.ua/
 */
class Update extends Action
{
    /**
     * Update status action method.
     * 
     * @return ResultFactory Result.
     */
    public function execute()
    {
        // echo 'My Backend Controller Works!';
        echo 'hello';
        exit;
        $result = $this->getResultFactory()->create(ResultFactory::TYPE_JSON);
        $result->setData(['hello' => 'mister', 'class' => static::class]);
        return $result;
    }
}