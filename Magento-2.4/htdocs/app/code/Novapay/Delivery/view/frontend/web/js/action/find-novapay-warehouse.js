/**
 * Novapay shipping method.
 * See COPYING.txt for license details.
 */

/**
 * @api
 */
define(
    [
        // 'Magento_Checkout/js/model/quote',
        'Magento_Checkout/js/model/url-builder',
        'mage/storage'
    ], 
    function (urlBuilder, storage) {
        'use strict';
        console.log('loaded find-novapay-warehouse.js');

        return function (cityRef) {
            var serviceUrl;

            serviceUrl = urlBuilder.createUrl('/guest-carts/shipping/novapay/warehouses/:cityRef', {
                cityRef: cityRef
            });

            var promise = new Promise(function (resolve, reject) {
    
                return storage.get(
                    serviceUrl
                ).done(
                    function (response) {
                        resolve(response);
                    }
                ).fail(
                    function (response) {
                        reject(response);
                    }
                );    
            });

            return promise;
        };
    }
);
