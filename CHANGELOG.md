# Changelog

All notable changes are documented here. The project follows [Semantic Versioning](https://semver.org/).

## 1.3.0 — 2026-10-13

- Bonus points of the OneTrace loyalty program ("Bonus points" setting, off by default): signed-in customers see their balance and pay for part of the order with points in the cart summary and in the checkout order summary. The points are held on the platform for 30 minutes and stored with the quote; the discount is the `onetrace_loyalty` total of the quote, order, invoice and credit memo (shown on order pages and in emails). Before the order is placed a changed cart or a hold about to expire is held again within the allowed share; the order confirms the points with its increment id, and cron retries if the platform is unavailable. Cancelled and refunded orders return the points on the platform.
- My Account → Bonus points: the balance, points waiting for the return period, the tier, points expiring soon and the latest operations; joining the program.
- The connection test checks the loyalty.read and loyalty.write permissions of the secret key.
- Requires onetrace-php 1.6.

## 1.2.0 — 2026-10-09

- Search redirects: the quick search results page of a query with a redirect rule (Site → Search of the platform, e.g. "delivery" → the delivery page) opens the rule's page. A short suggestion request is made before the page is built; later pages of the results and API errors keep the results page.

## 1.1.0 — 2026-10-09

- Catalog languages: store views of one project (the same address and secret key) with another locale add translations to the catalog of the first store view — product names, links of that store view, category names and the values of visible attributes. Recommendations, widgets, search and emails show the visitor's language.
- Product search from OneTrace (the "Product search" setting, off by default): the quick search results keep Magento's page and layered navigation, but take the products, their order and their number from the platform (word forms, typos, the wrong keyboard layout, the visitor's interests), with the price sorting and the category and price filters; the results page sends the `search` event. Filters by other attributes, sorting by name and any API error keep Magento's search.
- Suggestions while typing in the store search box (the tracker instead of Magento's autocomplete).
- Requires onetrace-php 1.4.

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
