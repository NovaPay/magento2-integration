/**
 * Copyright © 2026 NovaPay. All rights reserved.
 * Licensed under the MIT License. See LICENSE for details.
 */

/**
 * NovaPay hold confirmation form: amount checks (a number with at most 2 decimals, greater than zero,
 * not above the hold amount, partial only with a Recipient Identifier) and a confirmation dialog.
 * The server repeats every check and maps the amount to the invoice.
 */
define([
    'jquery',
    'Magento_Catalog/js/price-utils',
    'Magento_Ui/js/modal/confirm'
], function ($, priceUtils, confirm) {
    'use strict';

    return function (config, element) {
        var $form = $(element),
            $input = $form.find('.novapay-hold-confirm-amount'),
            $error = $form.find('.novapay-hold-confirm-error'),
            $submit = $form.find('.novapay-hold-confirm-submit'),
            submitLocked = $submit.prop('disabled'),
            confirmed = false;

        /**
         * @param {Number} amount
         * @returns {String}
         */
        var format = function (amount) {
            return priceUtils.formatPrice(amount, config.priceFormat);
        };

        /**
         * @returns {{amount: Number, partial: Boolean, error: String}}
         */
        var check = function () {
            var raw = $.trim(String($input.val() || '')).replace(/[\s ]/g, '').replace(',', '.'),
                amount = Number(raw),
                partial,
                error = '';

            if (!/^\d+(\.\d{1,2})?$/.test(raw)) {
                error = config.messages.invalid;
            } else if (amount <= 0) {
                error = config.messages.zero;
            } else if (amount - config.holdAmount > 0.001) {
                error = config.messages.over;
            }

            partial = Math.abs(config.holdAmount - amount) > 0.001;
            if (!error && partial && !config.partialAllowed) {
                error = config.messages.noRecipient;
            }

            $input.toggleClass('mage-error', error !== '');
            $error.text(error).toggleClass('no-display', error === '');
            $submit.prop('disabled', submitLocked || error !== '');

            return {
                amount: amount,
                partial: partial,
                error: error
            };
        };

        $input.on('input change', check);

        $form.on('submit', function (event) {
            var state;

            if (confirmed) {
                return true;
            }

            event.preventDefault();
            state = check();

            if (state.error || submitLocked) {
                return false;
            }

            confirm({
                content: (state.partial ? config.messages.confirmPartial : config.messages.confirmFull)
                    .replace('%1', format(state.amount)),
                actions: {
                    /**
                     * Submit once confirmed.
                     */
                    confirm: function () {
                        confirmed = true;
                        $submit.prop('disabled', true);
                        element.submit();
                    }
                }
            });

            return false;
        });

        if (!submitLocked) {
            check();
        }
    };
});
