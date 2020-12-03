<?php
/**
 * Payment mode selectbox in Admin UI.
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

use Magento\Framework\Option\ArrayInterface;
use Novapay\Payment\SDK\Model\Model;

/**
 * Payment mode selectbox in Admin UI.
 */
class PaymentMode implements ArrayInterface
{
    /**
     * {@inheritdoc}
     */
    public function toOptionArray()
    {
        return [
            [
                'value' => Model::MODE_TEST,
                'label' => __('Payment mode Test')
            ],
            [
                'value' => Model::MODE_LIVE,
                'label' => __('Payment mode Live')
            ]
        ];
    }
}
