/**
 * Copyright © 2026 NovaPay. All rights reserved.
 * Licensed under the MIT License. See LICENSE for details.
 */

define([
    'Magento_Checkout/js/view/payment/default',
    'Magento_Checkout/js/action/redirect-on-success',
    'Magento_Checkout/js/model/quote',
    'Magento_Checkout/js/model/step-navigator',
    'Magento_Ui/js/model/messageList'
], function (Component, redirectOnSuccessAction, quote, stepNavigator, messageList) {
    'use strict';

    /**
     * Mirrors NovaPay\Payment\Model\PhoneNumberValidator::normalize() — accepts, after stripping
     * everything but digits and a leading "+", `+380XXXXXXXXX`, `380XXXXXXXXX`, or `0XXXXXXXXX`
     * (nine digits). Keep both in sync.
     */
    var UA_PHONE_PATTERN = /^(?:\+?380|0)(\d{9})$/;

    /**
     * @param {String} phone
     * @return {Boolean}
     */
    var isValidUaPhone = function (phone) {
        // Keep only digits and a leading "+". JS's \d is always ASCII-only (unlike PHP's \d under
        // the /u modifier, which also matches Unicode decimal digits, e.g. full-width ０ U+FF10 or
        // Arabic-Indic ٥ U+0665) — must strip the exact same characters as
        // PhoneNumberValidator::normalize() server-side, or a phone can pass here and still be
        // rejected there.
        var stripped = String(phone || '').replace(/[^\d+]+/g, '');

        return UA_PHONE_PATTERN.test(stripped);
    };

    return Component.extend({
        defaults: {
            template: 'NovaPay_Payment/payment/novapay'
        },

        /**
         * @return {String}
         */
        getLogoUrl: function () {
            return window.checkoutConfig.payment.novapay.logoUrl;
        },

        /**
         * When phone_validation_enabled, reject placing the order if the shipping address
         * telephone (when there is a shipping step — skipped for virtual/downloadable-only carts)
         * or the billing address telephone does not match the Ukrainian +380 mask: show the
         * admin-configured message and prevent order placement (the server-side check in
         * CheckoutOrchestrator is the defense-in-depth backstop for this same rule, reached only if
         * this client-side check is bypassed). The shipping phone is checked first — it is edited on
         * the shipping step, so an invalid shipping phone sends the customer back there; an invalid
         * billing phone leaves the customer on the payment step, since that is where the billing
         * form lives in Luma.
         *
         * If there is no billing address yet, defer to the standard address validators
         * (`this._super()`) instead of treating "no address" as "invalid phone" — the latter would
         * show a misleading phone-specific error before the shopper has even filled the form.
         *
         * @return {Boolean}
         */
        validate: function () {
            var novapayConfig = window.checkoutConfig.payment.novapay,
                billingAddress = quote.billingAddress(),
                shippingAddress;

            if (!billingAddress) {
                return this._super();
            }

            if (novapayConfig.phoneValidationEnabled) {
                shippingAddress = !quote.isVirtual() ? quote.shippingAddress() : null;

                if (shippingAddress && !isValidUaPhone(shippingAddress.telephone)) {
                    messageList.addErrorMessage({
                        message: novapayConfig.phoneValidationErrorMessage
                    });
                    stepNavigator.navigateTo('shipping');

                    return false;
                }

                if (!isValidUaPhone(billingAddress.telephone)) {
                    messageList.addErrorMessage({
                        message: novapayConfig.phoneValidationErrorMessage
                    });

                    // The billing form lives on the payment step in Luma — stay here rather than
                    // navigating away, unlike the shipping-phone case above.
                    return false;
                }
            }

            return this._super();
        },

        /**
         * NovaPay is a redirect gateway: after the order is placed, send the customer to the
         * redirect controller (which builds the NovaPay session + payment and forwards to the
         * hosted payment frame) instead of the default checkout success page. `redirectAfterPlaceOrder`
         * stays true (inherited default) so the base component still triggers the redirect.
         *
         * @return {void}
         */
        afterPlaceOrder: function () {
            redirectOnSuccessAction.redirectUrl = window.checkoutConfig.payment.novapay.redirectUrl;
        }
    });
});
