define(
    [
        'uiComponent',
        'Magento_Checkout/js/model/shipping-rates-validator',
        'Magento_Checkout/js/model/shipping-rates-validation-rules',
        'Novapay_Delivery/js/model/shipping-rates-validator',
        'Novapay_Delivery/js/model/shipping-rates-validation-rules'
    ],
    function (
        Component,
        defaultShippingRatesValidator,
        defaultShippingRatesValidationRules,
        shippingRatesValidator,
        shippingRatesValidationRules
    ) {
        'use strict';
        defaultShippingRatesValidator.registerValidator('novapay', shippingRatesValidator);
        defaultShippingRatesValidationRules.registerRules('novapay', shippingRatesValidationRules);
        return Component;
    }
);
