/**
 * Links the browser to the signed-in customer: identify after login, reset after logout. The customer comes from
 * the private "onetrace" section, so it works with the full page cache.
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
