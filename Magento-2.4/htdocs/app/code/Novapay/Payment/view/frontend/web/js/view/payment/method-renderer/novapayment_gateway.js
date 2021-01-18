/**
 * Payment method renderer.
 * 
 * @category Module
 * @package  Magento2
 * @author   NovaPay <acquiring@novapay.ua>
 * @license  https://github.com/NovaPay/prestashop-integration/blob/master/LICENSE MIT
 * @link     https://novapay.ua/
 */
/*browser:true*/
/*global define*/
define(
    [
        'jquery',
        'Magento_Ui/js/model/messageList',
        'Magento_Checkout/js/view/payment/default',
        'Magento_Checkout/js/model/quote',
        'Magento_Checkout/js/model/full-screen-loader'
    ],
    function ($, messageList, Component, quote, fullScreenLoader) {
        'use strict';

        var READING_SUCCESS_MESSAGE_TIMEOUT = 1000;

        return Component.extend({
            defaults: {
                template: 'Novapay_Payment/payment/form',
                transactionResult: '',

                redirectUrl: window.checkoutConfig.defaultSuccessPageUrl
            },

            afterPlaceOrder: function () {
                // check for the proper URL to redirect
                this.redirectAfterPlaceOrder = false;

                fullScreenLoader.startLoader();
                $.ajax({
                    type: "POST",
                    url: "/novapay/payment/index",
                    data: {"x": "y"},
                    dataType: "json"
                }).done(function (data) {
                    fullScreenLoader.stopLoader();
                    if (data.messages) {
                        data.messages.forEach(function (msg) {
                            messageList.addSuccessMessage({message: msg});
                        });
                    }
                    if (data.payment && data.payment.url) {
                        setTimeout(function () {
                            fullScreenLoader.startLoader();
                            window.location.replace(data.payment.url);
                        }, READING_SUCCESS_MESSAGE_TIMEOUT);
                    }
                }).fail(function (xhr) {
                    var data = xhr.responseJSON;
                    fullScreenLoader.stopLoader();
                    if (data.errors) {
                        data.errors.forEach(function (err) {
                            messageList.addErrorMessage({message: err});
                        });
                    }
                });

                // redirect to payment processing 
                // this.redirectUrl = 'https://google.com';
                // fullScreenLoader.startLoader();
                // window.location.replace(this.redirectUrl);
            },

            isActive: function () {
                // sometimes billingAddress() is null for some reason :(
                var address  = quote.billingAddress() || quote.shippingAddress();
                if (!address) {
                    return false;
                }
                var country  = address.countryId;
                var currency = quote.totals().quote_currency_code;
                var phone    = address.telephone;
                if ('UAH' !== currency) {
                    return false;
                }
                if ('UA' !== country) {
                    return false;
                }
                if ('+380' !== phone.substr(0, 4)) {
                    return false;
                }
                return true;
            },

            // initObservable: function () {

            //     this._super()
            //         .observe([
            //             'transactionResult'
            //         ]);
            //     return this;
            // },

            getCode: function() {
                return 'novapayment_gateway';
            },

            getData: function() {
                return {
                    'method': this.item.method,
                    'additional_data': {
                        // @todo remove
                        // 'transaction_result': this.transactionResult()
                    }
                };
            },

            getTransactionResults: function() {
                return _.map(window.checkoutConfig.payment.novapayment_gateway.transactionResults, function(value, key) {
                    return {
                        'value': key,
                        'transaction_result': value
                    }
                });
            }
        });
    }
);