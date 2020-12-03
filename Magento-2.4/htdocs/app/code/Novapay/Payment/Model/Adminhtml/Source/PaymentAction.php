<?php
/**
 * Payment action (statuses) selectbox on order placement.
 * 
 * PHP version 7.X
 * 
 * @category Module
 * @package  Magento2
 * @author   NovaPay <acquiring@novapay.ua>
 * @license  https://github.com/NovaPay/prestashop-integration/blob/master/LICENSE MIT
 * @link     https://novapay.ua/
 */

namespace Novapay\Payment\Model\Adminhtml\Source;

use Magento\Payment\Model\Method\AbstractMethod;
use Magento\Framework\Option\ArrayInterface;
use Magento\Sales\Model\ResourceModel\Order\Status\CollectionFactory;

/**
 * Payment action (statuses) selectbox on order placement.
 * 
 * @category Module
 * @package  Magento2
 * @author   NovaPay <acquiring@novapay.ua>
 * @license  https://github.com/NovaPay/prestashop-integration/blob/master/LICENSE MIT
 * @link     https://novapay.ua/
 */
class PaymentAction implements ArrayInterface
{
    // const ACTION_ORDER = 'order';
    // const ACTION_AUTHORIZE = 'authorize';
    // const ACTION_AUTHORIZE_CAPTURE = 'authorize_capture';

    /**
     * @param CollectionFactory $statusCollectionFactory
     */
    public function __construct(CollectionFactory $statusCollectionFactory)
    {
        $this->statusCollectionFactory = $statusCollectionFactory;
    }

    /**
     * {@inheritdoc}
     */
    public function toOptionArray()
    {
        return array_merge(
            [
                ['value' => '', 'label' => __('Select Order Status')]
            ],
            $this->statusCollectionFactory->create()->toOptionArray()
        );
    }
}
