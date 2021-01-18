/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

define(
    [
        'jquery',
        'Magento_Customer/js/customer-data',
        'jquery/jquery-storageapi'
    ], 
    function (
        $,
        storage
    ) {
        'use strict';

        var cacheKey = 'novapay-delivery',

        /**
         * @param {Object} data
         */
        saveData = function (data) {
            storage.set(cacheKey, data);
        },

        /**
         * @return {*}
         */
        initData = function () {
            return {
                'city': { //Warehouse city
                    'ref': null,
                    'title': null,
                    'alt': null
                },
                'warehouse': { //Warehouse department
                    'ref': null,
                    'title': null
                },
                'city_cache': {

                }
            };
        },

        /**
         * @return {*}
         */
        getData = function () {
            var data = storage.get(cacheKey)();

            if ($.isEmptyObject(data)) {
                data = $.initNamespaceStorage('mage-cache-storage').localStorage.get(cacheKey);

                if ($.isEmptyObject(data)) {
                    data = initData();
                    saveData(data);
                }
            }

            return data;
        };

        return {
            getCity: function () {
                return getData().city;
            },

            getWarehouse: function () {
                return getData().warehouse
            },

            setCity: function (city) {
                var data = getData();
                data.city = city;
                saveData(data);
            },

            setWarehouse: function (warehouse) {
                var data = getData();
                data.warehouse = warehouse;
                saveData(data);
            },

            clearCity: function () {
                var data = initData();
                this.setCity(data.city);
            },

            clearWarehouse: function () {
                var data = initData();
                this.setWarehouse(data.warehouse);
            },

            cacheCity: function (city, items) {
                var data = getData();
                data.city_cache[city.ref] = items;
                saveData(data);
            },

            getCityCache: function (city) {
                var data = getData();
                return data.city_cache[city.ref] || [];
            }
        };
    }
);
