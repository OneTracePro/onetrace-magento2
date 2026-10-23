#!/usr/bin/env bash
# Storefront check over HTTP after tests/env/run.sh: the tracker in <head>, product_viewed with the product widget
# on a product page, /cdp-sw.js from the store root, the search box suggestions and the store search when the
# platform does not answer, the "Notify when available" form instead of Magento's stock alert on a sold-out product,
# the store MCP server at /onetrace/mcp (a POST without a form key, the platform key only), "Add to cart" of the
# OneTrace search widget at /onetrace/cart/add (the form key from the cookie, as the widget sends it).
set -euo pipefail
TAG="${MAGENTO_TAG:-php84-fpm-magento2.4.8-p5}"
NAME="onetrace-m2-${TAG//./-}"
PORT="${MAGENTO_PORT:-8892}"

PRODUCTS=$(docker exec "$NAME" sh -c '
    for path in "onetrace/connection/url https://cdp.example.com" "onetrace/connection/write_key cdp_wk_smoke" "onetrace/widgets/push 1" "onetrace/widgets/product k3x9" "onetrace/parts/search 1" "catalog/productalert/allow_stock 1" "onetrace/connection/secret_key cdp_sk_smoke" "onetrace/store_mcp/enabled 1"; do
        php bin/magento config:set $path >/dev/null
    done
    php bin/magento cache:flush >/dev/null
    php -r "
        require \"app/bootstrap.php\";
        \$om = \Magento\Framework\App\Bootstrap::create(BP, \$_SERVER)->getObjectManager();
        \$om->get(\Magento\Framework\App\State::class)->setAreaCode(\"adminhtml\");
        \$key = \"smoke-\" . bin2hex(random_bytes(3));
        \$p = \$om->create(\Magento\Catalog\Api\Data\ProductInterface::class)->setTypeId(\"simple\")->setAttributeSetId(4)->setSku(\$key)->setUrlKey(\$key)->setName(\"Smoke\")->setPrice(10)->setVisibility(4)->setStatus(1)->setWebsiteIds([1])->setStockData([\"qty\" => 5, \"is_in_stock\" => 1]);
        \$p = \$om->get(\Magento\Catalog\Api\ProductRepositoryInterface::class)->save(\$p);
        \$sold = \$om->create(\Magento\Catalog\Api\Data\ProductInterface::class)->setTypeId(\"simple\")->setAttributeSetId(4)->setSku(\$key . \"-sold\")->setUrlKey(\$key . \"-sold\")->setName(\"Smoke sold out\")->setPrice(10)->setVisibility(4)->setStatus(1)->setWebsiteIds([1])->setStockData([\"use_config_manage_stock\" => 1, \"qty\" => 0, \"is_in_stock\" => 0]);
        \$sold = \$om->get(\Magento\Catalog\Api\ProductRepositoryInterface::class)->save(\$sold);
        echo \$key, \" \", \$sold->getId(), \" \", \$om->get(\OneTrace\Connector\Model\StoreMcp::class)->key(), \" \", \$p->getId();
    "')

read -r URL_KEY SOLD_ID MCP_KEY PRODUCT_ID <<<"$PRODUCTS"

check() {
    if ! grep -qF -- "$3" <<<"$2"; then
        echo "FAIL: $1" >&2
        exit 1
    fi
    echo "ok: $1"
}

refute() {
    if grep -qF -- "$3" <<<"$2"; then
        echo "FAIL: $1" >&2
        exit 1
    fi
    echo "ok: $1"
}

HOME_PAGE=$(curl -fsS "http://localhost:$PORT/")
PRODUCT_PAGE=$(curl -fsS "http://localhost:$PORT/$URL_KEY.html")
SOLD_PAGE=$(curl -fsS "http://localhost:$PORT/$URL_KEY-sold.html")
WORKER=$(curl -fsS "http://localhost:$PORT/cdp-sw.js")
# cdp.example.com is not a platform: the results page falls back to Magento's search.
SEARCH_PAGE=$(curl -fsS "http://localhost:$PORT/catalogsearch/result/?q=$URL_KEY")

check "tracker in <head>" "$HOME_PAGE" "cdp.init(s.key,s.options)"
check "customer-data visitor" "$HOME_PAGE" "OneTrace_Connector/js/visitor"
check "product_viewed" "$PRODUCT_PAGE" "cdp.track('product_viewed'"
check "product widget" "$PRODUCT_PAGE" 'data-cdp-widget="k3x9" data-item='
check "service worker" "$WORKER" 'importScripts("https://cdp.example.com/tracker/cdp-sw.js")'
check "search box suggestions" "$HOME_PAGE" "i.setAttribute('data-cdp-search','')"
check "store search on platform errors" "$SEARCH_PAGE" "/$URL_KEY.html"
check "notify form on a sold-out product" "$SOLD_PAGE" "<div data-cdp-notify=\"$SOLD_ID\"></div>"
refute "no Magento stock alert with the form" "$SOLD_PAGE" "Notify me when this product is in stock"
refute "no notify form on a product in stock" "$PRODUCT_PAGE" "data-cdp-notify="

# With the form turned off Magento's own link is back.
docker exec "$NAME" sh -c 'php bin/magento config:set onetrace/parts/notify 0 >/dev/null && php bin/magento cache:flush >/dev/null'
SOLD_PAGE=$(curl -fsS "http://localhost:$PORT/$URL_KEY-sold.html")
docker exec "$NAME" sh -c 'php bin/magento config:set onetrace/parts/notify 1 >/dev/null && php bin/magento cache:flush >/dev/null'
refute "no notify form when turned off" "$SOLD_PAGE" "data-cdp-notify="
check "Magento stock alert without the form" "$SOLD_PAGE" "Notify me when this product is in stock"

MCP_INIT='{"jsonrpc":"2.0","id":1,"method":"initialize","params":{}}'
MCP_DENIED=$(curl -sS -o /dev/null -w '%{http_code}' -X POST -H 'Content-Type: application/json' -H 'Authorization: Bearer wrong' -d "$MCP_INIT" "http://localhost:$PORT/onetrace/mcp")
MCP=$(curl -fsS -X POST -H 'Content-Type: application/json' -H "Authorization: Bearer $MCP_KEY" -d "$MCP_INIT" "http://localhost:$PORT/onetrace/mcp")

check "store MCP refuses another key" "$MCP_DENIED" "401"
check "store MCP contract" "$MCP" '"onetrace/store":{"version":1}'

CART_URL="http://localhost:$PORT/onetrace/cart/add/id/$PRODUCT_ID"
CART_DENIED=$(curl -sS -o /dev/null -w '%{http_code}' -X POST -b 'form_key=smokeFormKey0001' -d 'quantity=1&form_key=smokeFormKey0001' "$CART_URL")
CART_FORM_KEY=$(curl -sS -o /dev/null -w '%{http_code}' -X POST -H 'X-Requested-With: XMLHttpRequest' -d 'quantity=1&form_key=smokeFormKey0001' "$CART_URL")
CART=$(curl -fsS -X POST -H 'X-Requested-With: XMLHttpRequest' -b 'form_key=smokeFormKey0001' -d 'quantity=2&form_key=smokeFormKey0001' "$CART_URL")
CART_SOLD=$(curl -fsS -X POST -H 'X-Requested-With: XMLHttpRequest' -b 'form_key=smokeFormKey0001' -d 'quantity=1&form_key=smokeFormKey0001' "http://localhost:$PORT/onetrace/cart/add/id/$SOLD_ID")

check "cart of the search widget refuses a request without X-Requested-With" "$CART_DENIED" "400"
check "cart of the search widget checks the form key" "$CART_FORM_KEY" "403"
check "cart of the search widget adds a product" "$CART" '{"ok":true,"count":2}'
check "cart of the search widget sends a sold-out product to its page" "$CART_SOLD" "\"redirect\":\"http:\\/\\/localhost:$PORT\\/$URL_KEY-sold.html\""
