/**
 * Copyright © 2026 NovaPay. All rights reserved.
 * Licensed under the MIT License. See LICENSE for details.
 */

define([
    'uiComponent',
    'Magento_Checkout/js/model/payment/renderer-list'
], function (Component, rendererList) {
    'use strict';

    rendererList.push({
        type: 'novapay',
        component: 'NovaPay_Payment/js/view/payment/method-renderer/novapay-method'
    });

    /* Registration-only module: the actual UI lives in the pushed renderer above. */
    return Component.extend({});
});
