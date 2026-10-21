# OneTrace.pro for Magento 2

[![CI](https://github.com/OneTracePro/onetrace-magento2/actions/workflows/ci.yml/badge.svg)](https://github.com/OneTracePro/onetrace-magento2/actions/workflows/ci.yml)
[![Packagist](https://img.shields.io/packagist/v/onetracepro/onetrace-magento2.svg)](https://packagist.org/packages/onetracepro/onetrace-magento2)
[![License](https://img.shields.io/packagist/l/onetracepro/onetrace-magento2.svg)](LICENSE)

Connects a Magento 2 / Adobe Commerce store to [OneTrace.pro](https://onetrace.pro), the customer data platform: orders, customers and newsletter consents from the server, the website tracker, the product catalog, recommendation widgets, Web Push and the store's emails through OneTrace. Abandoned cart, browse abandonment, post-purchase and win-back journeys, product recommendations and predictions work right after you connect the store.

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
| `order_shipped` — carrier, tracking number, shipped products | a shipment is created (admin, REST, shipping extensions) — with its tracking number, or when its first tracking number is added | server, once per shipment |
| `order_delivered` | the order moves to a status of the "Delivered order statuses" setting | server, once per order |
| `add_to_cart`, `remove_from_cart`, `checkout_started` | cart changes and the checkout page, for visitors with the tracker cookie or signed in | server |
| `add_to_wishlist`, `remove_from_wishlist` | Magento's wishlist: adding, removing, moving to the cart (storefront, GraphQL, admin) | server |
| `product_viewed`, page views | the storefront | browser tracker, works with the full page cache and Varnish |
| Product catalog with stock | product and stock changes (orders, cancellations, refunds, stock and source items), daily, and the "Upload the whole catalog" button | server, `products.write` |
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

1. In your OneTrace project, open **API keys** and create a write key (for the storefront) and a secret key with the `products.write` permission (for the server; add `messages.send` to send the store's emails through OneTrace).
2. In **Stores → Configuration → OneTrace** enter the platform address (the domain you open OneTrace at) and both keys, then click **Test connection**.
3. Make sure Magento cron runs: the queue is sent every minute.

## Shipments and delivery

- **Shipped:** each shipment of an order is one `order_shipped` (a second parcel is a new event): the carrier (the title of the tracking number, or the carrier code), the tracking number and the products of the shipment; the messageId ends with the shipment number. Magento does not store a tracking link, so `tracking_url` is not sent. A shipment created with a tracking number is sent at once; one without a tracking number waits for the first one added later (the "Add tracking number" button, the REST API, a shipping extension), and after an hour without one cron (`onetrace_shipments`) sends it without the number. Later tracking numbers and shipments made before the module was installed are not sent.
- **Delivered:** Magento has no delivery state. Choose the statuses that mean "the customer received the order" in **What to send → Delivered order statuses** (empty by default — nothing is sent), e.g. a "Delivered" status of **Stores → Order Status** that your shipping extension or staff set. An order that moves to one of them is sent once as `order_delivered`. "Complete" means invoiced and shipped, not delivered.
- The online chat AI of the platform answers "where is my order" with the carrier and the tracking number of these events.

## Store emails

Magento's emails — order confirmations, invoices, shipments, credit memos, registration, password resets — can go through the email integration of your OneTrace project instead of the store's mail server, with delivery statuses and history in the customer profile. Turn it on in **Store emails** of the platform (nothing to set in Magento); the secret key needs the `messages.send` permission ("Send messages manually"). **Stores → Configuration → OneTrace → Store emails** shows the state for the store view.

- **As is** (relay of store emails on): the rendered email — subject, HTML, text, sender name, reply-to and attachments (PDF, PNG, JPEG, GIF, text, CSV, calendar; up to 5 files and 5 MB) — goes to each recipient through OneTrace.
- **From OneTrace templates** (order emails chosen in the platform): instead of Magento's HTML the module sends the order — the number, the order page (My Account, or "Orders and Returns" for guests), products with SKU, image, link and options, totals, payment and shipping methods, addresses, the carrier, tracking number and tracking link of the shipment, the refunded amount of the credit memo — and OneTrace builds the email in the language of the store view.
- `order_delivered` and `order_cancelled` have no Magento email: with their template on, the module sends them once per order when the order moves to a status of "Delivered order statuses" or is cancelled.
- **Fallback:** whatever OneTrace does not accept — no email integration, the message quota is used up, too many emails to the recipient, no answer within 5 seconds — Magento sends itself, to the recipients OneTrace did not accept (one request per recipient, no retries). Emails with images embedded in the message (`cid:`) or attachments of other types are sent by Magento. With "Disable Email Communications" nothing is sent; emails of admin users (password reset, two-factor authentication) are always sent by Magento.
- The settings of the platform are read every 5 minutes and when the module settings are saved.
- Any mail transport works (sendmail, SMTP, SMTP extensions): the module is the first plugin of Magento's mail transport (`Magento\Framework\Mail\TransportInterface::sendMessage`, Laminas Mail in 2.4.7, Symfony Mailer in 2.4.8+), the kind of an email comes from its template in the `TransportBuilder` (custom templates of **Marketing → Email Templates** by the template they were made from).

| Magento email (template) | Kind | OneTrace template |
|---|---|---|
| New order (`sales_email_order_template`, `…_guest_template`) | `order_placed` | yes |
| Invoice (`sales_email_invoice_template`, guest) | `order_paid` | yes |
| Shipment (`sales_email_shipment_template`, guest) — with the tracking number of the shipment | `order_shipped` | yes |
| Credit memo (`sales_email_creditmemo_template`, guest) | `order_refunded` | yes |
| — (the order moves to a delivered status) | `order_delivered` | only from the template |
| — (the order is cancelled) | `order_cancelled` | only from the template |
| Comments of orders, invoices, shipments and credit memos | `order_note` | no |
| Ready for pickup (In-Store Pickup) | `order_ready_for_pickup` | no |
| Guest's confirmation of the order cancellation (link) | `login_link` | no |
| New account, account without a password, account confirmed | `account_new` | no |
| Account confirmation (link) | `email_verification` | no |
| Forgot password, password reminder | `password_reset` | no |
| Password changed | `password_changed` | no |
| Account information changed | `account_changed` | no |
| Newsletter confirmation, subscription, unsubscription | `newsletter_confirmation`, `newsletter_subscribed`, `newsletter_unsubscribed` | no |
| Shared wishlist, "Email to a friend" | `wishlist_shared`, `product_shared` | no |
| Price and stock alerts | `price_alert`, `stock_alert` | no |
| Contact form, payment failed, cron and import errors (to the staff) | — | always Magento |
| Copies to the staff ("Send Order Email Copy To", Bcc and Cc, any recipient other than the order's customer) | — | always Magento |
| Admin user emails (`admin_emails_*`, two-factor authentication, the admin area) | — | always Magento |
| Other emails of Magento and extensions | `other` (an order kind if the template has the order) | no |

Emails to the store owner and staff never go through OneTrace, also with relay on: there they would become customer profiles. In an order email with copies to the staff the customer's email goes through OneTrace, and Magento sends the copies to the staff only.

Emails with access links (`password_reset`, `account_new`, `email_verification`, `login_link`) are sent without click tracking, and only their subject is kept.

## Recommendations and Web Push

- Widgets from **Site → Widgets** of the platform: set their ids for the product page and the cart in the settings, or add the **OneTrace recommendations** widget in **Content → Widgets**. The current product, category and cart products are passed to the widget.
- Web Push: turn it on in the settings — the module serves `/cdp-sw.js` from the store root.

## Stock and "Notify when available"

- **Stock:** catalog items carry `stock` where the store tracks it — the salable quantity of the website's stock with Inventory (MSI: quantity minus reservations of open orders and the out-of-stock threshold), otherwise the quantity of the stock item. A configurable product has the sum of its enabled children. Products without "Manage Stock", with backorders (also from the store's defaults) or with such a child have no `stock`: zero there does not mean "sold out". Orders, cancellations, refunds and saved stock (the product form, the REST API, source items) send the product again. The platform uses the stock only with stock levels on in its catalog settings: zero takes the product off sale, and the "low stock" journey trigger works.
- **"Notify when available":** the page of a product that cannot be bought gets `<div data-cdp-notify="{product id}">` in the product info column; the tracker draws the form of the "Notify when available" kind from **Site → Forms** of the platform there, and a journey with the Catalog → "back in stock" trigger sends the notification. Magento's own "Notify me when this product is in stock" link is hidden on such pages. Turn it off with **What to send → Show the "Notify when available" form**.
- **Wishlist:** adding to and removing from Magento's wishlist are sent with the "Cart" setting, for price drop and back in stock journeys of products in the wishlist.

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
- **My Account → Bonus points:** the balance, points waiting for the return period, the tier, points expiring soon and the latest operations; "Join the program" for programs with explicit enrollment; the referral link with a copy button and the friends who came by it; active promo codes of the customer.
- **Referrals:** turn on referrals in the platform (Loyalty → Referrals). On registration the friend's code — from a `?ref=` link (the tracker keeps it in the `cdp_ref` cookie for 30 days) or from the optional "Friend's code" field (**What to send → Friend's code field**) — goes to the platform from cron, and the friend gets the welcome reward; the member gets points for the friend's first order.

Customers are found on the platform by `user_id` (the customer id), like in the order events.

## Promo codes

Pools of promo codes are set up in the **Promo codes** section of the platform; the discount is a cart price rule of the store. Create a rule (**Marketing → Cart Price Rules**) with the **Specific Coupon** type and **Use Auto Generation**, and bind it to the pool in **Stores → Configuration → OneTrace → Promo codes**, one per line:

```
welcome = 12
```

Every 5 minutes (cron `onetrace_promo_sync`) new and changed unique codes of the pool become coupons of the rule, one use each with the expiry of the code. Coupons of other rules with the same code are not touched; pools with one shared code are not synced — add that coupon to a rule yourself. The order events carry the coupon, so the platform counts the use. The secret key needs `promo.read`.

## AI chat bot: store MCP

The AI bot of the OneTrace chat can answer customers with live data of the store and, if you allow it, act on orders. Turn on **Connect to the chat bot** in **Stores → Configuration → OneTrace → AI chat bot: store MCP** and save: the module connects its MCP server (`POST /onetrace/mcp` of the store view, store MCP contract v1) to the bot of the project. Store views of one project and website share one connection. The secret key needs the `chats.manage` permission.

- **Reading** (on by default): orders of the customer (the account and guest orders with its email), an order with items, payment, shipping and the tracking number, stock and prices with configurable variants, active shipping methods for the country (flat rate per order and free shipping with its threshold have a price), the CMS page of the "Return policy page" setting.
- **Actions** (off by default, allow them in "What the bot may do"): cancel an unpaid order (states `new` and `pending_payment`, nothing paid; the `cancellableStates` argument of `OneTrace\Connector\Model\StoreMcp\Tools` in `di.xml` changes the states), pass a return or delivery change request to the staff — an order comment and the "Request email template" to the "Requests to the staff" address. The bot acts only after the customer agreed in the chat.
- **Customers**: signed-in customers are confirmed automatically (a signed token in the private `onetrace` section goes to `cdp.chatCustomer()`); guests and messenger customers get a 6-digit code by email ("Confirmation code email template"; an observer of `onetrace_store_mcp_send_code` can send it itself, e.g. by SMS, and set `sent` of the `transport`). Orders of other customers are "not found".

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
ONETRACE_PHP=../onetrace-php tests/env/run.sh             # with an unreleased onetrace-php from its working copy
tests/env/smoke.sh                                        # the storefront over HTTP after run.sh
```

The tests run inside [ready Magento images](https://hub.docker.com/r/michielgerritsen/magento-project-community-edition) with the module installed from the working copy: orders go through the real quote submit, invoices, credit memos and newsletter subscriptions.

## License

MIT
