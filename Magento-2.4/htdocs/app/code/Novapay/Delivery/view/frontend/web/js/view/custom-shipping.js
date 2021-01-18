/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

define(
    [
        'jquery',
        'Novapay_Delivery/js/model/search',
        // 'Novapay_Delivery/js/model/shipping-store',
        'Magento_Checkout/js/view/shipping',
        'Magento_Checkout/js/action/select-shipping-method',
        'Magento_Checkout/js/checkout-data',
        // 'Magento_Checkout/js/model/quote',
        'mage/translate'
    ], 
    function (
        $,
        search,
        // store,
        Component,
        selectShippingMethodAction,
        checkoutData,
        // quote,
        $t
    ) {
        'use strict';
        console.log('loaded custom-shipping.js');

        // initializes search model.
        return Component.extend({
        });
    }
);
