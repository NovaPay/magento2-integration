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
class LengthUnit implements ArrayInterface
{
    /**
     * {@inheritdoc}
     */
    public function toOptionArray()
    {
        return [
            [
                'value' => 'mm',
                'label' => __('Length unit mm')
            ],
            [
                'value' => 'cm',
                'label' => __('Length unit cm')
            ],
            [
                'value' => 'm',
                'label' => __('Length unit m')
            ],
        ];
    }
}
