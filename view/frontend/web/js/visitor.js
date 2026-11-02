/**
 * Links the browser to the signed-in customer: identify after login (with the signature of the id when the identity
 * key is set, and again when the signature changes), reset after logout, and the customer's token for the AI chat bot
 * (store MCP). The customer comes from the private "onetrace" section, so it works with the full
 * page cache.
 */
define(['Magento_Customer/js/customer-data'], function (customerData) {
    'use strict';

    return function () {
        var section = customerData.get('onetrace');
        var sync = function (data) {
            var id = data && data.id ? String(data.id) : '';
            var hash = data && data.hash ? String(data.hash) : '';
            var known = null;
            var knownHash = null;

            if (!window.cdp || !data || data.id === undefined) {
                return;
            }

            try {
                known = window.localStorage.getItem('cdp_uid');
                knownHash = window.localStorage.getItem('cdp_uhash');
            } catch (e) {
                known = null;
            }

            if (window.cdp.chatCustomer) {
                window.cdp.chatCustomer(data.chat || null);
            }

            if (id && (known !== id || (hash && knownHash !== hash))) {
                window.cdp.identify(id, {}, hash ? { userHash: hash } : {});
            } else if (!id && known) {
                window.cdp.reset();
            }
        };

        sync(section());
        section.subscribe(sync);
    };
});
