// @todo remove file
require(['jquery', 'jquery/jquery.cookie'], function () {
    jQuery('body').on('click', '.novapay-payment-logo', function (e) {
        if (e.target.className === 'novapay-payment-logo' || e.target.className === 'open' || e.target.className === 'entry-edit-head admin__collapsible-block') {
            var public = jQuery('#payment_us_Novapay_Payment_public_key');
            var private = jQuery('#payment_us_Novapay_Payment_private_key');
            var pass = jQuery('#payment_us_Novapay_Payment_private_key_pass');
            if (public.val() !== '' && public.val() !== '******************') {
                jQuery.cookie('public', public.val());
                public.val('******************');
            }
            if (private.val() !== '' && private.val() !== '******************') {
                jQuery.cookie('private', private.val());
                private.val('******************');
            }
            if (pass.val() !== '' && pass.val() !== '******************') {
                jQuery.cookie('pass', pass.val());
                pass.val('******************');
            }
        }
    });

    var intv = setInterval(function () {
        jQuery('.page-actions-buttons').append('<div class="novapay-temp-blc"></div>');
    }, 1000);

    jQuery('body').on('click', '.novapay-temp-blc', function () {
        if(jQuery('#payment_us_Novapay_Payment_public_key').val() === '******************') {
            jQuery('#payment_us_Novapay_Payment_public_key').val(jQuery.cookie('public'));
        }
        if(jQuery('#payment_us_Novapay_Payment_private_key').val() === '******************') {
            jQuery('#payment_us_Novapay_Payment_private_key').val(jQuery.cookie('private'));
        }
        if(jQuery('#payment_us_Novapay_Payment_private_key_pass').val() === '******************') {
            jQuery('#payment_us_Novapay_Payment_private_key_pass').val(jQuery.cookie('pass'));
        }
        jQuery('#save').click();
        jQuery('#row_payment_us_Novapay_Payment').hide();
    });

});
