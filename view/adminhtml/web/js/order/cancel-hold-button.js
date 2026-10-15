/**
 * Copyright © 2026 NovaPay. All rights reserved.
 * Licensed under the MIT License. See LICENSE for details.
 */

/**
 * NovaPay refund button on a hold: confirm, then POST (with the admin form key) to novapay/order/cancelHold.
 */
define([
    'jquery',
    'Magento_Ui/js/modal/confirm'
], function ($, confirm) {
    'use strict';

    return function (config, element) {
        $(element).on('click', function (event) {
            event.preventDefault();

            confirm({
                content: config.message,
                actions: {
                    /**
                     * Post the cancel request.
                     */
                    confirm: function () {
                        $('<form>', {
                            action: config.url,
                            method: 'POST'
                        }).append($('<input>', {
                            type: 'hidden',
                            name: 'form_key',
                            value: window.FORM_KEY
                        })).appendTo('body').trigger('submit');
                    }
                }
            });

            return false;
        });
    };
});
