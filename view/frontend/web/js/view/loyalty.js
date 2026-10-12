/* The bonus points field in the order summary of the checkout. */
define(['uiComponent', 'OneTrace_Connector/js/loyalty'], function (Component, loyalty) {
    'use strict';

    return Component.extend({
        defaults: {
            template: 'OneTrace_Connector/loyalty'
        },

        init: function (element) {
            loyalty({}, element);
        }
    });
});
