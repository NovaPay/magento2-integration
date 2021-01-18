/**
 * Novapay shipping method.
 * See COPYING.txt for license details.
 */

/**
 * @api
 */
define(
    [
        'Magento_Checkout/js/model/quote',
        'Magento_Checkout/js/model/url-builder',
        'mage/storage',
        'Novapay_Delivery/js/model/shipping-store'
    ], 
    function (quote, urlBuilder, storage, store) {
        'use strict';
        console.log('loaded calculate-cost.js');

        function encodeTitle(title) {
            // escape special caracters for URL routing
            // test with the city: Київ
            //          warehouse: Відділення №27
            var result = btoa(unescape(encodeURIComponent(title)));
            result = result.replace(/\=/g, '');
            result = result.replace(/\//g, '-');
            return result;
        }

        return function () {
            var serviceUrl;

            var promise = new Promise(function (resolve, reject) {

                serviceUrl = urlBuilder.createUrl('/guest-carts/:cartId/shipping/novapay/calculate/:cityRef/:warehouseRef/:cityTitle/:warehouseTitle', {
                    cartId: quote.getQuoteId(),
                    cityRef: store.getCity().ref,
                    warehouseRef: store.getWarehouse().ref,
                    cityTitle: encodeTitle(store.getCity().title),
                    warehouseTitle: encodeTitle(store.getWarehouse().title)
                });
    
                return storage.get(serviceUrl).done(resolve).fail(reject);
            });

            return promise;
        };
    }
);
