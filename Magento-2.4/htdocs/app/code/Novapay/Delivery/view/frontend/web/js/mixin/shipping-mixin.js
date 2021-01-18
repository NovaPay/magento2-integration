define(
    [
    ], function (
    ) {
        'use strict';

        return function (target) {
            return target.extend({
                setShippingInformation: function () {
                    if (this.validateReferences()) {
                        this._super();
                    }
                },
                validateReferences: function() {
                    this.source.set('params.invalid', false);
                    this.source.trigger('city_ref.data.validate');
                    this.source.trigger('warehouse_ref.data.validate');

                    console.log('validating in mixin');

                    if (this.source.get('params.invalid')) {
                        return false;
                    }

                    return true;
                }
            });
        }
    }
);