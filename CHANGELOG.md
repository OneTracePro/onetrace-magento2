# Changelog

All notable changes are documented here. The project follows [Semantic Versioning](https://semver.org/).

## 1.0.0 — 2026-10-08

First release.

- Orders from the server: placed (any checkout, REST, GraphQL, admin), paid (invoice paid), cancelled and refunded (credit memos), with deterministic message ids; later order events land in the profile of the checkout browser.
- Customers: identify on registration, login, account and address changes and with every order; guest orders are linked by email.
- Newsletter subscriptions of the store (registration, account, footer form, admin) become email consents.
- Cart events from the server: add_to_cart, remove_from_cart, checkout_started.
- The website tracker with product views, cached with the page; the signed-in customer comes from a private customer-data section; waits for "Cookie Restriction Mode" consent.
- Product catalog sync: on product changes, daily and on demand; configurable children upload their parent, categories with ancestors, brands and visible attributes.
- Recommendation widgets (settings and the "OneTrace recommendations" CMS widget) and Web Push (/cdp-sw.js).
- Settings per store view with the connection check and the queue state; cron queue with retries; sending pauses on an invalid key until the check passes; `bin/magento onetrace:test|status|flush|sync-catalog`; English and Russian.
