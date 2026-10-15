/**
 * Copyright © 2026 NovaPay. All rights reserved.
 * Licensed under the MIT License. See LICENSE for details.
 */

/**
 * NovaPay refund form: live "amount to refund" per line and the total, input checks, confirmation.
 * The submit posts refund_mode "partial" when a quantity is entered, otherwise "full" after the
 * admin confirmed a full refund. The server recalculates everything; the credit memo total is what
 * is refunded.
 */
define([
    'jquery',
    'Magento_Catalog/js/price-utils',
    'Magento_Ui/js/modal/confirm'
], function ($, priceUtils, confirm) {
    'use strict';

    return function (config, element) {
        var $form = $(element),
            $submit = $form.find('.novapay-refund-submit'),
            $mode = $form.find('.novapay-refund-mode'),
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
         * @returns {{total: Number, partial: Boolean, invalid: Boolean}}
         */
        var recalc = function () {
            var total = 0,
                partial = false,
                invalid = false;

            $form.find('.novapay-refund-qty').each(function () {
                var $input = $(this),
                    itemId = String($input.data('item-id')),
                    raw = $.trim(String($input.val() || '')).replace(',', '.'),
                    qty = raw === '' ? 0 : Number(raw),
                    max = parseFloat($input.attr('max')),
                    wholeOnly = $input.attr('step') === '1',
                    bad = raw !== '' && (isNaN(qty) || qty < 0 || qty > max + 0.0001 ||
                        wholeOnly && Math.abs(qty - Math.round(qty)) > 0.0001),
                    line;

                $input.toggleClass('mage-error', bad);
                invalid = invalid || bad;
                qty = bad ? 0 : qty;
                line = Math.round((config.unitAmounts[itemId] || 0) * qty * 100) / 100;

                if (qty > 0) {
                    partial = true;
                }
                total += line;
                $form.find('.novapay-refund-line-amount[data-item-id="' + itemId + '"]').text(format(line));
            });

            total = partial ? Math.round(total * 100) / 100 : config.fullAmount;
            $form.find('.novapay-refund-total').text(format(total));
            $form.find('.novapay-refund-mode-full').toggleClass('no-display', partial);
            $form.find('.novapay-refund-mode-partial').toggleClass('no-display', !partial);
            $submit.prop('disabled', submitLocked || invalid);

            return {
                total: total,
                partial: partial,
                invalid: invalid
            };
        };

        $form.on('input change', '.novapay-refund-qty', recalc);

        $form.on('submit', function (event) {
            var state;

            if (confirmed) {
                return true;
            }

            event.preventDefault();
            state = recalc();

            if (state.invalid || submitLocked) {
                return false;
            }

            confirm({
                content: (state.partial ? config.confirmPartial : config.confirmFull).replace('%1', format(state.total)),
                actions: {
                    /**
                     * Submit once confirmed.
                     */
                    confirm: function () {
                        $mode.val(state.partial ? 'partial' : 'full');
                        confirmed = true;
                        $submit.prop('disabled', true);
                        element.submit();
                    }
                }
            });

            return false;
        });

        recalc();
    };
});
