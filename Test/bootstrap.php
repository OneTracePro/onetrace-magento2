<?php

declare(strict_types=1);

/**
 * Integration tests run inside the Magento container of tests/env/run.sh: the real application with the module
 * installed; requests to the platform go to a recording transport.
 */

use Magento\Framework\App\Area;
use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\State;

require '/data/app/bootstrap.php';

$bootstrap = Bootstrap::create(BP, $_SERVER);
$GLOBALS['onetraceObjectManager'] = $bootstrap->getObjectManager();
// The admin area, as in a real store where products, invoices and credit memos are made; the module's server
// observers are global. Storefront parts are checked over HTTP by tests/env/smoke.sh.
$GLOBALS['onetraceObjectManager']->get(State::class)->setAreaCode(Area::AREA_ADMINHTML);
$GLOBALS['onetraceObjectManager']->configure($GLOBALS['onetraceObjectManager']->get(\Magento\Framework\ObjectManager\ConfigLoaderInterface::class)->load(Area::AREA_ADMINHTML));

spl_autoload_register(static function (string $class): void {
    $prefix = 'OneTrace\\Connector\\Test\\';

    if (str_starts_with($class, $prefix)) {
        require __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    }
});
