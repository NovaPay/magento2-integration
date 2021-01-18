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
        console.log('loaded find-novapay-city.js');

        return function (city, searchLimit) {
            searchLimit = searchLimit || 3;
            var serviceUrl;

            var promise = new Promise(function (resolve, reject) {
                if (city.length < searchLimit) {
                    // @todo remove logging
                    console.warn('City name is too short to search ' + city.length);
                    return new Promise(function (resolve, reject) {
                        resolve([]);
                    });
                }
    
                serviceUrl = urlBuilder.createUrl('/guest-carts/shipping/novapay/cities/:city', {
                    city: city
                });
    
                return storage.get(serviceUrl).done(resolve).fail(reject);
            });

            return promise;
        };
    }
);
