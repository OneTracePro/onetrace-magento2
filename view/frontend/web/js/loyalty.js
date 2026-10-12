/* Bonus points of the OneTrace loyalty program in the cart and the checkout: the state comes from onetrace/loyalty/state,
   applying and removing — onetrace/loyalty/apply, then the totals of the cart are reloaded. */
define([
    'jquery',
    'mage/url',
    'Magento_Checkout/js/action/get-totals',
    'Magento_Customer/js/customer-data',
    'jquery/jquery.cookie'
], function ($, url, getTotals, customerData) {
    'use strict';

    function text(value) {
        return $('<div>').text(value === null || value === undefined ? '' : String(value)).html();
    }

    function render(element, state, error) {
        var $element = $(element);

        if (!state) {
            $element.empty().hide();

            return;
        }

        var html = '<div class="onetrace-loyalty__title"><strong>' + text(state.title) + '</strong></div>';

        if (state.link) {
            html += '<a href="' + text(state.link.url) + '">' + text(state.link.text) + '</a>';
        } else if (state.applied) {
            html += '<div>' + text(state.applied) + ' <button type="button" class="action action-remove onetrace-loyalty__remove">' + text(state.remove) + '</button></div>';
        } else {
            html += '<div>' + text(state.balance) + '</div>';

            if (state.max > 0) {
                html += '<div class="onetrace-loyalty__form" style="display:flex;gap:8px;margin-top:8px">'
                    + '<input type="number" class="input-text onetrace-loyalty__points" min="1" step="1" max="' + Number(state.max) + '" value="' + Number(state.max) + '" aria-label="' + text(state.field) + '" style="width:8em">'
                    + '<button type="button" class="action primary onetrace-loyalty__apply"><span>' + text(state.apply) + '</span></button></div>'
                    + '<div class="note">' + text(state.hint) + '</div>';
            }
        }

        if (state.earn) {
            html += '<div class="note">' + text(state.earn) + '</div>';
        }

        if (error) {
            html += '<div class="message error" role="alert"><span>' + text(error) + '</span></div>';
        }

        $element.html(html).show();
    }

    return function (config, element) {
        var $element = $(element).css({ margin: '15px 0' });
        var current = null;

        function show(state, error) {
            current = state;
            render(element, state, error);
        }

        function load() {
            $.ajax({ url: url.build('onetrace/loyalty/state'), cache: false, dataType: 'json' }).done(function (response) {
                show(response.loyalty, null);
            });
        }

        function send(data) {
            $element.css('opacity', 0.5);
            $.ajax({
                url: url.build('onetrace/loyalty/apply'),
                type: 'POST',
                dataType: 'json',
                data: $.extend({ form_key: $.mage.cookies.get('form_key') }, data)
            }).done(function (response) {
                show(response.loyalty, null);
                getTotals([]);
                customerData.reload(['cart'], false);
            }).fail(function (xhr) {
                show(current, (xhr.responseJSON && xhr.responseJSON.message) || xhr.statusText);
            }).always(function () {
                $element.css('opacity', '');
            });
        }

        $element.on('click', '.onetrace-loyalty__apply', function () {
            send({ mode: 'apply', points: $element.find('.onetrace-loyalty__points').val() });
        });
        $element.on('keydown', '.onetrace-loyalty__points', function (event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                $element.find('.onetrace-loyalty__apply').trigger('click');
            }
        });
        $element.on('click', '.onetrace-loyalty__remove', function () {
            send({ mode: 'remove' });
        });

        load();
    };
});
