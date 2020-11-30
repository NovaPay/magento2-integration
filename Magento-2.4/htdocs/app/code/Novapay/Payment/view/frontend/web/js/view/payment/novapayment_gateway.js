/*browser:true*/
/*global define*/
define(
    [
        'uiComponent',
        'Magento_Checkout/js/model/payment/renderer-list'
    ],
    function (
        Component,
        rendererList
    ) {
        'use strict';
        rendererList.push(
            {
                type: 'novapayment_gateway',
                component: 'Novapay_Payment/js/view/payment/method-renderer/novapayment_gateway'
            }
        );
        /** Add view logic here if needed */
        return Component.extend({});
    }
);
