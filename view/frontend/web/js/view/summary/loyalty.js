/* The bonus points row of the cart and checkout totals (the onetrace_loyalty total segment). */
define(['Magento_Checkout/js/view/summary/abstract-total', 'Magento_Checkout/js/model/totals'], function (Component, totals) {
    'use strict';

    return Component.extend({
        defaults: {
            template: 'OneTrace_Connector/summary/loyalty'
        },

        segment: function () {
            return totals.getSegment('onetrace_loyalty');
        },

        isDisplayed: function () {
            var segment = this.segment();

            return !!segment && Number(segment.value) !== 0;
        },

        title: function () {
            var segment = this.segment();

            return segment ? segment.title : '';
        },

        value: function () {
            var segment = this.segment();

            return this.getFormattedPrice(segment ? segment.value : 0);
        }
    });
});
