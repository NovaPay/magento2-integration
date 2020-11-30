<?php
/**
 * Payment type selectbox in Admin UI.
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

/**
 * Payment type selectbox in Admin UI.
 */
class PaymentType implements ArrayInterface
{
    const HOLD   = 'hold';
    const DIRECT = 'direct';

    /**
     * {@inheritdoc}
     */
    public function toOptionArray()
    {
        return [
            [
                'value' => self::HOLD,
                'label' => __('Payment type Hold')
            ],
            [
                'value' => self::DIRECT,
                'label' => __('Payment type Direct')
            ]
        ];
    }
}
