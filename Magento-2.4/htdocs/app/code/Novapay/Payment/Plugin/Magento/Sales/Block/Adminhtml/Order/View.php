<?php
/**
 * Order view to handle Confirm Hold button.
 * 
 * PHP version 7.X
 * 
 * @category Module
 * @package  Magento2
 * @author   NovaPay <acquiring@novapay.ua>
 * @license  https://github.com/NovaPay/prestashop-integration/blob/master/LICENSE MIT
 * @link     https://novapay.ua/
 */

namespace Novapay\Payment\Plugin\Magento\Sales\Block\Adminhtml\Order;

use Magento\Sales\Block\Adminhtml\Order\View as OrderView;
use Novapay\Payment\Block\Adminhtml\Order\ModalBox;

/**
 * Order view to handle Confirm Hold button.
 * HTML modal box only.
 * Button is adding in the plugin Novapay\Payment\Plugin\PluginBtnOrderView.
 */
class View
{
    public function afterToHtml(OrderView $subject, $result) 
    {
        if ($subject->getNameInLayout() == 'sales_order_edit') {
            $customBlockHtml = $subject->getLayout()->createBlock(
                ModalBox::class,
                $subject->getNameInLayout() . '_novapayment_modal_box'
            )->setOrder($subject->getOrder())
                ->setTemplate('Novapay_Payment::order/modalbox.phtml')
                ->toHtml();
            return $result . $customBlockHtml;
        }
        return $result;
    }
}

