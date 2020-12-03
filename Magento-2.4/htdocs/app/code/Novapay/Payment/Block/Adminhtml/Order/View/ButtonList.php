<?php

namespace Novapay\Payment\Block\Adminhtml\Order\View;

use Magento\Backend\Block\Widget\Button\ButtonList as ButtonListBase;
use Magento\Backend\Block\Widget\Button\ItemFactory;

// @deprecated @todo remove class & from di.xml
class ButtonList extends ButtonListBase
{
    public function __construct(ItemFactory $itemFactory)
    {
        parent::__construct($itemFactory);
        // $this->add(
        //     'mybutton', 
        //     [
        //         'label' => __('My button label')
        //     ]
        // );
    }
}
