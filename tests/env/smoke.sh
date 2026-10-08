#!/usr/bin/env bash
# Storefront check over HTTP after tests/env/run.sh: the tracker in <head>, product_viewed with the product widget
# on a product page, and /cdp-sw.js from the store root.
set -euo pipefail
TAG="${MAGENTO_TAG:-php84-fpm-magento2.4.8-p5}"
NAME="onetrace-m2-${TAG//./-}"
PORT="${MAGENTO_PORT:-8892}"

URL_KEY=$(docker exec "$NAME" sh -c '
    for path in "onetrace/connection/url https://cdp.example.com" "onetrace/connection/write_key cdp_wk_smoke" "onetrace/widgets/push 1" "onetrace/widgets/product k3x9"; do
        php bin/magento config:set $path >/dev/null
    done
    php bin/magento cache:flush >/dev/null
    php -r "
        require \"app/bootstrap.php\";
        \$om = \Magento\Framework\App\Bootstrap::create(BP, \$_SERVER)->getObjectManager();
        \$om->get(\Magento\Framework\App\State::class)->setAreaCode(\"adminhtml\");
        \$key = \"smoke-\" . bin2hex(random_bytes(3));
        \$p = \$om->create(\Magento\Catalog\Api\Data\ProductInterface::class)->setTypeId(\"simple\")->setAttributeSetId(4)->setSku(\$key)->setUrlKey(\$key)->setName(\"Smoke\")->setPrice(10)->setVisibility(4)->setStatus(1)->setWebsiteIds([1])->setStockData([\"qty\" => 5, \"is_in_stock\" => 1]);
        \$om->get(\Magento\Catalog\Api\ProductRepositoryInterface::class)->save(\$p);
        echo \$key;
    "')

check() {
    if ! grep -qF -- "$3" <<<"$2"; then
        echo "FAIL: $1" >&2
        exit 1
    fi
    echo "ok: $1"
}

HOME_PAGE=$(curl -fsS "http://localhost:$PORT/")
PRODUCT_PAGE=$(curl -fsS "http://localhost:$PORT/$URL_KEY.html")
WORKER=$(curl -fsS "http://localhost:$PORT/cdp-sw.js")

check "tracker in <head>" "$HOME_PAGE" "cdp.init(s.key,s.options)"
check "customer-data visitor" "$HOME_PAGE" "OneTrace_Connector/js/visitor"
check "product_viewed" "$PRODUCT_PAGE" "cdp.track('product_viewed'"
check "product widget" "$PRODUCT_PAGE" 'data-cdp-widget="k3x9" data-item='
check "service worker" "$WORKER" 'importScripts("https://cdp.example.com/tracker/cdp-sw.js")'
