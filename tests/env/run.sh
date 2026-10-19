#!/usr/bin/env bash
# Integration tests in Docker: a ready Magento (michielgerritsen/magento-project-community-edition: MySQL,
# Elasticsearch, PHP-FPM, nginx in one image) with this module installed from the working copy, PHPUnit inside.
#   tests/env/run.sh                                         — Magento 2.4.8, PHP 8.4
#   MAGENTO_TAG=php82-fpm-magento2.4.7-p10 tests/env/run.sh  — another version
#   ONETRACE_PHP=../onetrace-php tests/env/run.sh            — an unreleased onetrace-php from its working copy
set -euo pipefail
cd "$(dirname "$0")/../.."
TAG="${MAGENTO_TAG:-php84-fpm-magento2.4.8-p5}"
NAME="onetrace-m2-${TAG//./-}"
PORT="${MAGENTO_PORT:-8892}"

# The library from a working copy, as the version in its Client::VERSION: a path repository of the container only.
sdk() {
    [ -n "${ONETRACE_PHP:-}" ] || return 0
    local version
    version=$(sed -n "s/.*const VERSION = '\([0-9.]*\)'.*/\1/p" "$ONETRACE_PHP/src/Client.php")
    docker exec "$NAME" sh -c 'rm -rf /data/sdk/onetrace-php && mkdir -p /data/sdk/onetrace-php'
    tar -C "$ONETRACE_PHP" --exclude=./vendor --exclude=./.git -cf - . | docker exec -i "$NAME" tar -C /data/sdk/onetrace-php -xf -
    docker exec "$NAME" composer config repositories.onetrace-php "{\"type\":\"path\",\"url\":\"sdk/onetrace-php\",\"options\":{\"symlink\":false,\"versions\":{\"onetracepro/onetrace-php\":\"$version\"}}}"
}

if ! docker ps --format '{{.Names}}' | grep -qx "$NAME"; then
    docker rm -f "$NAME" >/dev/null 2>&1 || true
    docker run -d --name "$NAME" -p "$PORT:80" -e "URL=http://localhost:$PORT/" -e DISABLE_2FA=true \
        -v "$PWD:/data/extensions/onetrace-magento2" "michielgerritsen/magento-project-community-edition:$TAG" >/dev/null
    for _ in $(seq 1 120); do
        docker exec "$NAME" sh -c 'mysqladmin ping --silent >/dev/null 2>&1 && php bin/magento --version >/dev/null 2>&1' && break
        sleep 3
    done
    sdk
    docker exec "$NAME" sh -c '
        set -e
        composer config repositories.onetrace path extensions/onetrace-magento2
        # Composer 2.10 refuses packages with security advisories, and Magento 2.4.7 itself locks league/flysystem 2.
        composer config policy.advisories.block false 2>/dev/null || true
        composer require "onetracepro/onetrace-magento2:@dev" --no-plugins --no-interaction --quiet
        php bin/magento module:enable OneTrace_Connector >/dev/null
        php bin/magento setup:upgrade --keep-generated >/dev/null
        php bin/magento deploy:mode:set developer >/dev/null
        php bin/magento indexer:set-mode realtime >/dev/null
        php bin/magento cache:flush >/dev/null'
elif [ -n "${ONETRACE_PHP:-}" ]; then
    sdk
    docker exec "$NAME" composer update onetracepro/onetrace-php onetracepro/onetrace-magento2 --no-plugins --no-interaction --quiet
fi

# Mailpit of the image receives the emails Magento sends itself (store email tests).
docker exec "$NAME" sh -c 'supervisorctl start mailpit >/dev/null 2>&1 || true'
docker exec "$NAME" sh -c 'php bin/magento setup:db:status >/dev/null || php bin/magento setup:upgrade --keep-generated >/dev/null; rm -rf generated/code/OneTrace; php vendor/bin/phpunit -c extensions/onetrace-magento2/Test/phpunit.xml.dist '"$*"
