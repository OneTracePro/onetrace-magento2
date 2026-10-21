/**
 * Links the browser to the signed-in customer: identify after login, reset after logout, and the customer's token
 * for the AI chat bot (store MCP). The customer comes from the private "onetrace" section, so it works with the full
 * page cache.
 */
define(['Magento_Customer/js/customer-data'], function (customerData) {
    'use strict';

    return function () {
        var section = customerData.get('onetrace');
        var sync = function (data) {
            var id = data && data.id ? String(data.id) : '';
            var known = null;

            if (!window.cdp || !data || data.id === undefined) {
                return;
            }

            try {
                known = window.localStorage.getItem('cdp_uid');
            } catch (e) {
                known = null;
            }

            if (window.cdp.chatCustomer) {
                window.cdp.chatCustomer(data.chat || null);
            }

            if (id && known !== id) {
                window.cdp.identify(id);
            } else if (!id && known) {
                window.cdp.reset();
            }
        };

        sync(section());
        section.subscribe(sync);
    };
});
