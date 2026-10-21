# Changelog

All notable changes are documented here. The project follows [Semantic Versioning](https://semver.org/).

## 1.8.0 — 2026-10-22

- MCP server of the store for the OneTrace AI chat bot (store MCP contract v1, onetrace-php 1.13): `POST /onetrace/mcp` of a store view, connected to the bot of the project when the settings are saved (**AI chat bot: store MCP**, the secret key needs `chats.manage`; store views of one project and website share one connection, turning it off disconnects it). The platform key and the token secret are generated once and kept encrypted in flags.
- Reading tools on by default: orders of the customer (the account and guest orders with its email), an order with items, payment, shipping and the tracking number, tracking, stock with configurable variants, shipping methods, the return policy from a CMS page. Actions off by default: cancel an unpaid order (`new`/`pending_payment`, nothing paid; `cancellableStates` in `di.xml`), return and delivery change requests to the staff (an order comment and an email, once per idempotency key). The owner chooses the tools in "What the bot may do".
- Customers are confirmed by the login on the storefront (a signed token in the private `onetrace` section → `cdp.chatCustomer()`, tracker 1.16.0) or by a 6-digit code by email (new email templates "OneTrace: chat confirmation code" and "OneTrace: request from the chat"; the `onetrace_store_mcp_send_code` event for SMS). With store emails through OneTrace the code goes as `login_code` (not tracked, redacted after sending); requests to the staff stay in Magento.
- Requires onetrace-php 1.13, Magento_Cms and Magento_Shipping.

## 1.7.0 — 2026-10-20

- Store emails through OneTrace (Store emails of the platform, the secret key needs `messages.send`): Magento's emails go through the email integration of the project, with delivery statuses and history in the customer profile. The module is the first plugin of the mail transport (`TransportInterface::sendMessage`: Laminas Mail in 2.4.7, Symfony Mailer in 2.4.8+, before SMTP extensions) and learns the kind of an email and its order from the template of the `TransportBuilder` (custom templates by the template they were made from); the kinds are listed in the README.
- Relay: the rendered subject, HTML, text, sender name, reply-to and attachments (PDF, images, text, CSV, calendar; up to 5 files and 5 MB) go to each recipient as they are.
- Order emails from OneTrace templates (`order_placed`, `order_paid`, `order_shipped`, `order_refunded`): the order with its number, storefront link, products with SKU, image, link and options, totals, payment and shipping methods, addresses, the carrier, tracking number and tracking link of the shipment and the refunded amount, in the language of the store view. `order_delivered` (on the "Delivered order statuses") and `order_cancelled`, which Magento has no emails for, are sent once per order when their template is on. Emails to the store owner and staff — copies of order emails (Copy To, Bcc, Cc, any recipient other than the order's customer), the contact form, failed payments, cron and import errors — and emails of admin users always stay in Magento, also with relay on (they would become customer profiles on the platform); the customer's email of an order with staff copies goes through OneTrace and Magento sends the copies to the staff only.
- Whatever OneTrace does not accept (an error, the quota, no answer within 5 seconds, no retries) Magento sends itself, to the recipients OneTrace did not accept; emails with embedded `cid:` images or other attachment types too. "Disable Email Communications" is respected.
- The settings of the platform (`emails()->settings()`) are kept in the Magento cache for 5 minutes and read again when the module settings are saved; **Stores → Configuration → OneTrace → Store emails** shows the state. The connection test notes a secret key without `messages.send`.
- Requires onetrace-php 1.12 and Magento_Email.

## 1.6.0 — 2026-10-19

- `order_shipped` once per shipment (a second parcel of the order is a new event, the messageId ends with the shipment's increment id): the carrier (the title of the first track, or its carrier code), the tracking number and the shipped products. A shipment created with a tracking number is sent at once; one without waits for its first track added later (`sales_order_shipment_track_save_after`: the "Add tracking number" button, the REST API, shipping extensions), and the `onetrace_shipments` cron job sends it without the number after an hour. The state is kept in the new `sales_shipment.onetrace_shipped` column; shipments made before the update are not sent. No `tracking_url`: Magento does not store one.
- `order_delivered` once per order when it moves to a status of the new "Delivered order statuses" setting (What to send, empty by default: Magento has no delivery state), remembered in the new `sales_order.onetrace_delivered_at` column.
- Requires onetrace-php 1.11.

## 1.5.0 — 2026-10-18

- Stock in the catalog: `stock` of a product is the salable quantity of the website's stock with Inventory (MSI), otherwise the quantity of the legacy stock item above the out-of-stock threshold; a configurable product has the sum of its enabled children. Products without stock management or with backorders (their own or the store's default), and configurable products with such a child, have no `stock`. The platform uses it only with stock levels on in its catalog settings.
- Products are sent again when their stock or salability changes: a placed or cancelled order, a refunded credit memo, a saved stock item, saved or deleted source items of Inventory; a configurable parent goes with its children.
- `available` of catalog items comes from the stock index of the website (it was true for enabled products that were out of stock); a product not indexed yet keeps its status.
- Wishlist events from the server with the "Cart" setting: `add_to_wishlist` for a new item of Magento's wishlist and `remove_from_wishlist` when the last item of the product is removed — by the customer, with a quantity of 0, by moving it to the cart, through GraphQL or the admin. The customer id comes with the tracker cookie of the customer's own browser on the storefront.
- "Notify when available": the page of a product that cannot be bought gets `<div data-cdp-notify="{product id}">`, where the tracker draws the form of that kind (Site → Forms of the platform); Magento's own "Notify me when this product is in stock" link is hidden there. The "Show the “Notify when available” form" setting, on by default.
- Requires onetrace-php 1.10 and Magento_CatalogInventory.

## 1.4.0 — 2026-10-13

- Promo codes: Stores → Configuration → OneTrace → Promo codes binds a pool of OneTrace promo codes to a cart price rule with a specific coupon and auto generation ("pool_key = rule id" per line). Every 5 minutes (cron) new and changed codes of the pool come by the cursor of the platform and become coupons of the rule, one use each with the expiry of the code; coupons of other rules with the same code are left as they are. The connection test checks the promo.read permission.
- Referrals: on registration the friend's code — from the optional "Friend's code" field or from the tracker's `cdp_ref` cookie of a `?ref=` link — is sent to the platform from cron (`loyalty()->refer()`), repeated while the new customer is unknown to it; the friend gets the welcome reward at once.
- My Account → Bonus points: the referral link with a copy button, friends who came and the points for them, active promo codes of the customer; referral rewards in the history.
- Requires onetrace-php 1.7.

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
