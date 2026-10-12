# OneTrace.pro for Magento 2

[![CI](https://github.com/OneTracePro/onetrace-magento2/actions/workflows/ci.yml/badge.svg)](https://github.com/OneTracePro/onetrace-magento2/actions/workflows/ci.yml)
[![Packagist](https://img.shields.io/packagist/v/onetracepro/onetrace-magento2.svg)](https://packagist.org/packages/onetracepro/onetrace-magento2)
[![License](https://img.shields.io/packagist/l/onetracepro/onetrace-magento2.svg)](LICENSE)

Connects a Magento 2 / Adobe Commerce store to [OneTrace.pro](https://onetrace.pro), the customer data platform: orders, customers and newsletter consents from the server, the website tracker, the product catalog, recommendation widgets and Web Push. Abandoned cart, browse abandonment, post-purchase and win-back journeys, product recommendations and predictions work right after you connect the store.

[Русская версия](README.ru.md)

- Magento Open Source and Adobe Commerce 2.4.7–2.4.9, PHP 8.2–8.5
- Orders and their statuses are sent from the server through a cron queue with retries: checkout never waits for the platform
- Settings per store view: each store view can send to its own project; store views of one project in other languages translate its catalog
- The same events and properties as every OneTrace shop plugin ([e-commerce contract](https://onetrace.pro/en/docs/api)), built on [onetrace-php](https://github.com/OneTracePro/onetrace-php)

## What it sends

| Data | When | How |
|---|---|---|
| `identify` — email, phone, name, city, country, language | registration, login, account and address changes, every order | server, secret key |
| Newsletter consent | subscribing and unsubscribing in the store: registration, account, footer form, admin | server, with `identify` |
| `order_completed` | the order is placed (any checkout, REST, GraphQL, admin) | server |
| `order_paid`, `order_refunded`, `order_cancelled` | an invoice is paid, a credit memo is refunded, the order is cancelled | server, once per order event |
| `add_to_cart`, `remove_from_cart`, `checkout_started` | cart changes and the checkout page, for visitors with the tracker cookie or signed in | server |
| `product_viewed`, page views | the storefront | browser tracker, works with the full page cache and Varnish |
| Product catalog | product changes, daily, and the "Upload the whole catalog" button | server, `products.write` |
| `search` | the first page of the store search results, with "Product search" on | server |

Guest orders without the tracker cookie are linked by email; later events of an order (invoice, refund) use the browser of its checkout, so everything lands in one profile.

## Installation

```bash
composer require onetracepro/onetrace-magento2
bin/magento module:enable OneTrace_Connector
bin/magento setup:upgrade
bin/magento setup:di:compile         # production mode
bin/magento setup:static-content:deploy
```

1. In your OneTrace project, open **API keys** and create a write key (for the storefront) and a secret key with the `products.write` permission (for the server).
2. In **Stores → Configuration → OneTrace** enter the platform address (the domain you open OneTrace at) and both keys, then click **Test connection**.
3. Make sure Magento cron runs: the queue is sent every minute.

## Recommendations and Web Push

- Widgets from **Site → Widgets** of the platform: set their ids for the product page and the cart in the settings, or add the **OneTrace recommendations** widget in **Content → Widgets**. The current product, category and cart products are passed to the widget.
- Web Push: turn it on in the settings — the module serves `/cdp-sw.js` from the store root.

## Languages of the catalog

The catalog of a project comes from its first store view. Other store views connected to the same project (the same address and secret key) with another locale (**Stores → Configuration → General → Locale Options**) add translations: product names, their links in that store view, category names and the values of visible attributes. The platform shows recommendations, widgets, search results and emails in the visitor's language.

## Product search

Turn on **Product search** in the settings (the plan of the project must include it):

- The quick search results page keeps your theme and layered navigation, but its products, their order and their number come from OneTrace: word forms, typos, a query typed in the wrong keyboard layout, the visitor's interests. Sorting by price and the category and price filters go to the platform.
- The search box shows suggestions of the tracker while typing: popular queries, categories and products with pictures and prices.
- A query with a redirect rule (Site → Search of the platform, e.g. "delivery" → the delivery page) opens the rule's page instead of the results. The full page cache does not store redirects, but a popular query cached before its rule was added shows the cached results until the cache is refreshed.
- Filters by other attributes, sorting by name and any error of the platform keep Magento's own search. The layered navigation counts come from Magento's search.

## Bonus points

Turn on **Stores → Configuration → OneTrace → What to send → Bonus points**; the program itself (earning, tiers, the share of an order payable with points) is set up in the **Loyalty** section of the platform, and the secret key needs the `loyalty.read` and `loyalty.write` permissions.

- **Cart and checkout:** a signed-in customer sees the balance, how many points the cart can take and the points for the order in the cart summary and in the checkout order summary, enters the points and clicks "Use points". The points are held on the platform for 30 minutes; the discount is the `onetrace_loyalty` total (a row of the totals of the cart, order, invoice, credit memo, order pages and emails). Guests see a hint to sign in.
- **Order:** right before the order is placed a changed cart or a hold about to expire is held again within the allowed share; the order confirms the points with its increment id (cron retries if the platform is unavailable). Cancelled and refunded orders return the points on the platform.
- **My Account → Bonus points:** the balance, points waiting for the return period, the tier, points expiring soon and the latest operations; "Join the program" for programs with explicit enrollment.

Customers are found on the platform by `user_id` (the customer id), like in the order events.

## Privacy

- With **Cookie Restriction Mode** on, the tracker loads after the visitor allows cookies (can be turned off in the settings).
- Consents come from the store's own newsletter subscriptions; "Not activated" (waiting for the confirmation email) is not a subscription yet.
- Data already sent stays in the OneTrace project; delete profiles there (GDPR).

## CLI

```bash
bin/magento onetrace:test --store=1   # checks both keys of a store view
bin/magento onetrace:status           # queue, last error, paused store views
bin/magento onetrace:flush            # sends what is waiting now
bin/magento onetrace:sync-catalog     # uploads the whole catalog now
```

## Development

```bash
tests/env/run.sh                                          # integration tests: Magento 2.4.8 in Docker
MAGENTO_TAG=php82-fpm-magento2.4.7-p10 tests/env/run.sh   # another version
```

The tests run inside [ready Magento images](https://hub.docker.com/r/michielgerritsen/magento-project-community-edition) with the module installed from the working copy: orders go through the real quote submit, invoices, credit memos and newsletter subscriptions.

## License

MIT
