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
use Magento\Catalog\Model\ResourceModel\Eav\Attribute;

/**
 * Product attributes (statuses) selectbox for delivery dimensions.
 * 
 * @category Module
 * @package  Magento2
 * @author   NovaPay <acquiring@novapay.ua>
 * @license  https://github.com/NovaPay/prestashop-integration/blob/master/LICENSE MIT
 * @link     https://novapay.ua/
 */
class Attributes implements ArrayInterface
{
    protected $attributeFactory;

    /**
     * @param ColAttribute $attributeFactory
     */
    public function __construct(Attribute $attributeFactory)
    {
        $this->attributeFactory = $attributeFactory;
    }

    /**
     * {@inheritdoc}
     */
    public function toOptionArray()
    {
        $options = [];
        $attributeInfo = $this->attributeFactory->getCollection();
        foreach ($attributeInfo as $item) {
            $options[] = [
                'value' => $item->getAttributeCode(),
                'label' => $item->getFrontendLabel()
            ];
        }
        return $options;
    }
}
