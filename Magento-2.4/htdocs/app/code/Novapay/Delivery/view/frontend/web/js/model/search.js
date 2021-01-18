/**
 * Novapay shipping method.
 * See COPYING.txt for license details.
 */

/**
 * @api
 */
define(
    [
        'jquery',
        'Novapay_Delivery/js/action/find-novapay-city',
        'Novapay_Delivery/js/action/find-novapay-warehouse',
        'Novapay_Delivery/js/action/calculate-cost',
        'Novapay_Delivery/js/model/shipping-store',
        'Novapay_Delivery/js/form-mini',
        'Magento_Checkout/js/model/quote',
        'Magento_Checkout/js/checkout-data',
        'Magento_Checkout/js/model/shipping-rate-registry'
        // 'Magento_Checkout/js/model/full-screen-loader'
    ], 
    function (
        $,
        findCityAction,
        findWarehouseAction,
        calculateAction,
        store,
        searchWidget,
        quote,
        checkoutData,
        rateRegistry
        // fullScreenLoader
    ) {
        'use strict';
        console.log('loaded search.js');

        var component = {
            MIN_SEARCH_LENGTH: 2,
            silenceMode: false,

            /**
             * Initializes the search component.
             *
             * @param {bool} silenceMode If TRUE don't focus on warehouse.
             */
            initialize: function (silenceMode) {
                this.silenceMode = silenceMode || false;
                console.log('search.start');

                this.city()
                    .on('focus', $.proxy(this.onCityFocus, this))
                    .on('blur', $.proxy(this.onCityBlur, this));
                this.warehouse()
                    .on('focus', $.proxy(this.onWarehouseFocus, this))
                    .on('blur', $.proxy(this.onWarehouseBlur, this));

                this.initCitySearch();

                // load after search widget
                this.load();
            },

            shippings: function () {
                // also possible #opc-shipping_method
                return $('#checkout-step-shipping_method');
            },
            city: function (isForm) {
                var $element = $('[data-role="city-search"]');
                if (!isForm) {
                    return $element;
                }
                return $element.parents('form:first');
            },
            warehouse: function (isForm) {
                var $element = $('[data-role="warehouse-search"]');
                if (!isForm) {
                    return $element;
                }
                return $element.parents('form:first');
            },

            updateCity: function () {
                var city = store.getCity();
                this.city().val(city.title);
            },
            updateWarehouse: function () {
                var warehouse = store.getWarehouse();
                this.warehouse().val(warehouse.title);
            },

            enableWarehouse: function () {
                this.warehouse().removeAttr('disabled');
            },
            disableWarehouse: function () {
                this.warehouse().attr('disabled', true);
            },

            /**
             * Sets inputs values and data attributes.
             * 
             * @return {bool} TRUE on success (if city & warehouse are defined),
             *              FALSE on failure.
             */
            load: function () {
                var city = store.getCity();
                var warehouse = store.getWarehouse();
                console.log(city, warehouse);
                if (!city.ref && !city.title) {
                    return false;
                }

                this.city()
                    .data({
                        // 'ref': city.ref,
                        // 'title': city.title,
                        'chosen': true
                    })
                    .val(city.title);
                
                if (!warehouse.ref && !warehouse.title) {
                    return false;
                }
                this.warehouse()
                    .data({
                        // 'ref': warehouse.ref,
                        // 'title': warehouse.title,
                        'chosen': true
                    })
                    .val(warehouse.title);
                
                return true;
            },

            shippingMethodChanged: function (data) {
                if ('novapay_novapay' !== (data.carrier_code + '_' + data.method_code)) {
                    return false;
                }
                if (this.warehouse().length) {
                    this.initialize();
                    return true;
                }
                // wait for shipping methods HTML loaded
                var interval = setInterval($.proxy(function () {
                    if (!this.warehouse().length) {
                        return;
                    }
                    this.initialize(true);
                    clearInterval(interval);
                }, this), 100);
                return true;
            },

            initCitySearch: function (dontLoadAutocomplete) {
                var that = this;
                var address = checkoutData.getShippingAddressFromData();
                var city = store.getCity();
                var searchByCity = '';
                if (!dontLoadAutocomplete) {
                    if (city.alt == address.city && city.ref) {
                        // empty city == address.city
                        // skip city search
                        this.updateCity();
                        that.initWarehouseSearch();
                    } else if (!city.title && !city.alt && address.city) {
                        // empty city/warehouse but address.city entered
                        searchByCity = address.city;
                    } else if (city.alt != address.city || !city.ref) {
                        // empty city != address.city or delivery.city is undefined
                        searchByCity = address.city;
                        store.setCity({ref: '', title: '', alt: searchByCity});
                        store.setWarehouse({ref: '', title: ''});
                    }
                }

                if (this.city().data('citySearch')) {
                    this.city().citySearch('destroy');
                }
                this.city().citySearch({
                    minSearchLength: that.MIN_SEARCH_LENGTH,
                    value: searchByCity,
                    formSelector: 'form[data-role="city-search-form"]',
                    destinationSelector: '[data-role="city-search-results"]',
                    resolver: function (q) {
                        var promise = new Promise(function (resolve, reject) {
                            findCityAction(q, that.MIN_SEARCH_LENGTH).then(function(data) {
                                that.onCityFound.call(that, data);
                                resolve(data);
                            }, function(error) {
                                reject(error);
                            });
                        });
                        return promise;
                    },
                    onSelect: function(data) {
                        data.alt = address.city;
                        that.onCitySelect.call(that, data);
                        store.setCity(data);
                        that.initWarehouseSearch();
                    }
                });
            },

            initWarehouseSearch: function () {
                var that = this;
                findWarehouseAction(store.getCity().ref).then(function (data) {
                    that.onWarehouseFound.call(that, data);
                    if (that.warehouse().data('citySearch')) {
                        that.warehouse().citySearch('destroy');
                    }
                    that.warehouse().citySearch({
                        minSearchLength: 1,
                        formSelector: 'form[data-role="warehouse-search-form"]',
                        destinationSelector: '[data-role="warehouse-search-results"]',
                        resolver: function (q) {
                            var result = [];
                            $.each(data, $.proxy(function (index, item) {
                                var str = '' + item.no + ' ' + item.title;
                                if (str.indexOf(q) > -1) {
                                    result.push(item);
                                }
                            }, this));
    
                            var promise = new Promise(function (resolve, reject) {
                                resolve(result);
                            });
                            return promise;
                        },
                        onSelect: $.proxy(that.onWarehouseSelect, that)
                    });
                }, function (error) {
                    // error
                    console.error('Warehouse find action error!')
                    console.error(error.responseJSON.message);
                });    
            },

            onSelect: function (data) {
                var address = checkoutData.getShippingAddressFromData();
                store.setCity(
                    {
                        ref: data.city_ref,
                        title: data.city_title,
                        alt: address.city
                    }
                );
                store.setWarehouse(
                    {
                        ref: data.warehouse_ref,
                        title: data.warehouse_title,
                        no: data.no
                    }
                );

                this.city().val(data.city_title).data('chosen', true);
                this.warehouse().val(data.warehouse_title).data('chosen', true);
            },

            onCityFound: function (data) {

            },
            onCitySelect: function () {
                this.silenceMode = false;
                if (this.warehouse().data('citySearch')) {
                    store.clearWarehouse();
                    this.updateWarehouse();
                }
                store.clearWarehouse();
                this.warehouse().trigger('processStart');
                this.updateWarehouse();
            },
            onCityFocus: function () {
            },
            onCityBlur: function () {
            },

            onWarehouseFound: function (data) {
                var warehouse = this.warehouse();
                warehouse.val('').trigger('processStop');
                if (!this.silenceMode) {
                    warehouse.focus();
                }
                this.silenceMode = true;
                this.updateWarehouse();
            },
            onWarehouseSelect: function (data) {
                store.setWarehouse({
                    ref: data.id,
                    title: data.title,
                    no: data.no
                });
                this.shippings().trigger('processStart');
                calculateAction().then($.proxy(this.onCalculate, this));
            },
            onWarehouseFocus: function () {
            },
            onWarehouseBlur: function () {
            },

            onCalculate: function (data) {
                // this is the private function
                // estimateService.estimateTotalsAndUpdateRates();
                // fullScreenLoader.stopLoader();
                this.shippings().trigger('processStop');

                var address = quote.shippingAddress();
                rateRegistry.set(address.getKey(), null);
                rateRegistry.set(address.getCacheKey(), null);
                quote.shippingAddress(address);

                var reload = true;

                quote.shippingMethod.subscribe($.proxy(function (value) {
                    if (!reload) {
                        return;
                    }
                    this.load();
                    reload = false;
                }, this));
            }
        };

        quote.shippingMethod.subscribe(
            $.proxy(component.shippingMethodChanged, component)
        );

        return component;
    }
);
