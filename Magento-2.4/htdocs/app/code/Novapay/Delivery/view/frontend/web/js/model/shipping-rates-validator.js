define(
    [
        'jquery',
        'mageUtils',

        'Novapay_Delivery/js/model/shipping-rates-validation-rules',
        'mage/translate'
    ],
    function ($, utils, validationRules, __) {
        'use strict';
        console.log('loaded shipping-rates-validator.js');

        return {
            validationErrors: [],
            validate: function(address) {
                var self = this;
                this.validationErrors = [];
                $.each(validationRules.getRules(), function(field, rule) {
                    if (rule.required && utils.isEmpty(address[field])) {
                        var message = __('Field ') + field + __(' is required.');
                        self.validationErrors.push(message);
                    }
                    if (rule.exact && address[field] != rule.exact) {
                        var message = __('Field ') + field + __(' must be ') + rule.exact;
                        self.validationErrors.push(message);
                    }
                });
                return !Boolean(this.validationErrors.length);
            }
        };
    }
);
