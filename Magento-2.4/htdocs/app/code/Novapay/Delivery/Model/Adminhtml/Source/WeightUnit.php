<?php
/**
 * Product attributes (statuses) selectbox for delivery dimensions.
 * 
 * PHP version 7.X
 * 
 * @category Module
 * @package  Magento2
 * @author   NovaPay <acquiring@novapay.ua>
 * @license  https://github.com/NovaPay/prestashop-integration/blob/master/LICENSE MIT
 * @link     https://novapay.ua/
 */

namespace Novapay\Delivery\Model\Adminhtml\Source;

use Magento\Framework\Option\ArrayInterface;

/**
 * Product attributes (statuses) selectbox for delivery dimensions.
 * 
 * @category Module
 * @package  Magento2
 * @author   NovaPay <acquiring@novapay.ua>
 * @license  https://github.com/NovaPay/prestashop-integration/blob/master/LICENSE MIT
 * @link     https://novapay.ua/
 */
class WeightUnit implements ArrayInterface
{
    /**
     * {@inheritdoc}
     */
    public function toOptionArray()
    {
        return [
            // [
            //     'value' => '',
            //     'label' => __('Use system product weight')
            // ],
            [
                'value' => 'g',
                'label' => __('Weight unit g')
            ],
            [
                'value' => 'kg',
                'label' => __('Weight unit kg')
            ],
        ];
    }
}
