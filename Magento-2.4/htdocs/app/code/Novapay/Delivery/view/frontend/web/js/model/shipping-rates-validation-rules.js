define(
    // the problem is the semicolon after define();
    // is it cached?
    [],
    function () {
        'use strict';
        return {
            getRules: function() {
                return {
                    'postcode': {
                        'required': true
                    },
                    'country_id': {
                        'required': true//,
                        // 'exact': 'UA'
                    },
                    'city': {
                        'required': true
                    }
                };
            }
        };
    }
)