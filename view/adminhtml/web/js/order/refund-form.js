/**
 * Copyright © 2026 NovaPay. All rights reserved.
 * Licensed under the MIT License. See LICENSE for details.
 */

/**
 * NovaPay refund form: per line a quantity and an editable "amount to refund" (a quantity change
 * fills the amount with quantity x unit amount, the admin may then change it), the live total, input
 * checks and the confirmation. The submit posts refund_mode "partial" when a quantity or an amount
 * above 0 is entered, otherwise (all fields empty or 0) "full" after the admin confirmed a full
 * refund. The server validates and maps everything again; the credit memo total is what is refunded.
 */
define([
    'jquery',
    'mage/translate',
    'Magento_Catalog/js/price-utils',
    'Magento_Ui/js/modal/confirm'
], function ($, $t, priceUtils, confirm) {
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
         * @param {Number} value
         * @returns {Number}
         */
        var cents = function (value) {
            return Math.round(value * 100) / 100;
        };

        /**
         * @param {jQuery} $input
         * @returns {String}
         */
        var rawValue = function ($input) {
            return $.trim(String($input.val() || '')).replace(',', '.');
        };

        /**
         * Whether the browser could not parse a number field (it then reports an empty value).
         *
         * @param {jQuery} $input
         * @returns {Boolean}
         */
        var isBadInput = function ($input) {
            var node = $input[0];

            return Boolean(node && node.validity && node.validity.badInput);
        };

        /**
         * Whether a field holds a value other than empty or zero, as the server rounds it: an amount
         * to cents, a quantity above 0.0001. A zero quantity or amount means "nothing for this line",
         * like an empty field (spec 8.2.5: nothing entered = full refund). Anything not a number
         * counts as entered, so its error is shown.
         *
         * @param {jQuery} $input
         * @param {Boolean} isQty
         * @returns {Boolean}
         */
        var hasValue = function ($input, isQty) {
            var raw = rawValue($input),
                value = Number(raw);

            if (isBadInput($input)) {
                return true;
            }
            if (raw === '') {
                return false;
            }
            if (isNaN(value)) {
                return true;
            }

            return isQty ? Math.abs(value) > 0.0001 : cents(value) !== 0;
        };

        /**
         * Highlight a field and show (or remove) its inline error text.
         *
         * @param {jQuery} $input
         * @param {String} message empty when the field is valid
         */
        var markField = function ($input, message) {
            var $error = $input.siblings('.novapay-refund-field-error');

            $input.toggleClass('mage-error _error', message !== '');
            $input.attr('aria-invalid', message !== '' ? 'true' : 'false');
            if (message === '') {
                $error.remove();

                return;
            }
            if (!$error.length) {
                $error = $('<div class="novapay-refund-field-error" role="alert"></div>').insertAfter($input);
            }
            $error.text(message);
        };

        /**
         * @param {jQuery} $input
         * @returns {{qty: Number, bad: Boolean}}
         */
        var readQty = function ($input) {
            var raw = rawValue($input),
                qty = raw === '' ? 0 : Number(raw),
                max = parseFloat($input.attr('max')),
                wholeOnly = $input.attr('step') === '1',
                bad = isBadInput($input) || raw !== '' && (isNaN(qty) || qty < 0 || qty > max + 0.0001 ||
                    wholeOnly && Math.abs(qty - Math.round(qty)) > 0.0001);

            return {qty: bad ? 0 : qty, bad: bad};
        };

        /**
         * Fill the amount of a line from its quantity.
         *
         * @param {jQuery} $qtyInput
         */
        var fillAmount = function ($qtyInput) {
            var itemId = String($qtyInput.data('item-id')),
                state = readQty($qtyInput),
                $amount = $form.find('.novapay-refund-amount[data-item-id="' + itemId + '"]');

            if (state.bad) {
                return;
            }
            $amount.val(state.qty > 0 ? cents((config.unitAmounts[itemId] || 0) * state.qty).toFixed(2) : '');
        };

        /**
         * Recalculate the line amounts' validity and the total.
         *
         * While a field is invalid the total shows the valid entries only and the submit is disabled.
         *
         * @returns {{total: Number, partial: Boolean, invalid: Boolean}}
         */
        var recalc = function () {
            var total = 0,
                partial = false,
                invalid = false,
                $total = $form.find('.novapay-refund-total');

            $form.find('.novapay-refund-amount').each(function () {
                var $amount = $(this),
                    itemId = String($amount.data('item-id')),
                    unit = config.unitAmounts[itemId] || 0,
                    $qty = $form.find('.novapay-refund-qty[data-item-id="' + itemId + '"]'),
                    qtyState = $qty.length ? readQty($qty) : {qty: 0, bad: false},
                    raw = rawValue($amount),
                    amount = raw === '' ? cents(unit * qtyState.qty) : Number(raw),
                    max = parseFloat($amount.attr('max')),
                    qtyEntered = $qty.length > 0 && hasValue($qty, true),
                    message = '';

                if (qtyState.bad) {
                    markField($qty, config.errorQty.replace('%1', $qty.attr('max')));
                } else if ($qty.length) {
                    markField($qty, '');
                }

                if ($amount.prop('disabled')) {
                    // Nothing left to refund in money (for example a free item): only its quantity counts.
                    if (qtyEntered) {
                        partial = true;
                    }
                    if (qtyState.bad) {
                        invalid = true;
                    }

                    return;
                }

                if (isBadInput($amount) ||
                    raw !== '' && (isNaN(amount) || amount < 0 || amount > max + 0.005)) {
                    message = config.errorAmount.replace('%1', format(max));
                } else if (raw !== '' && qtyState.qty > 0 && amount === 0 && unit > 0) {
                    message = config.errorZeroAmount;
                }
                markField($amount, message);

                if (qtyEntered || hasValue($amount, false)) {
                    partial = true;
                }
                if (message !== '' || qtyState.bad) {
                    invalid = true;

                    return;
                }
                total += amount;
            });

            total = partial ? cents(total) : config.fullAmount;
            if (partial && !invalid && total > config.maxTotal + 0.005) {
                invalid = true;
                $total.addClass('mage-error')
                    .attr('title', config.totalTooHigh.replace('%1', format(config.maxTotal)));
            } else {
                $total.removeClass('mage-error').removeAttr('title');
            }
            $total.text(format(total));
            $form.find('.novapay-refund-mode-invalid').toggleClass('no-display', !invalid);
            $form.find('.novapay-refund-mode-full').toggleClass('no-display', partial || invalid);
            $form.find('.novapay-refund-mode-partial').toggleClass('no-display', !partial || invalid);
            $submit.prop('disabled', submitLocked || invalid);

            return {
                total: total,
                partial: partial,
                invalid: invalid
            };
        };

        $form.on('input change', '.novapay-refund-qty', function () {
            fillAmount($(this));
            recalc();
        });
        $form.on('input change', '.novapay-refund-amount', recalc);

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
                content: (state.partial ? config.confirmPartial : config.confirmFull)
                    .replace('%1', format(state.total)),
                buttons: [{
                    text: $t('Cancel'),
                    class: 'action-secondary action-dismiss',

                    /**
                     * Close without refunding.
                     *
                     * @param {Event} closeEvent
                     */
                    click: function (closeEvent) {
                        this.closeModal(closeEvent);
                    }
                }, {
                    text: $t('Confirm refund'),
                    class: 'action-primary action-accept',

                    /**
                     * Close and run the confirm action.
                     *
                     * @param {Event} closeEvent
                     */
                    click: function (closeEvent) {
                        this.closeModal(closeEvent, true);
                    }
                }],
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
