<?php

declare(strict_types=1);

namespace OneTrace\Connector\Controller;

use Magento\Framework\App\ActionFactory;
use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\RouterInterface;
use Magento\Store\Model\StoreManagerInterface;
use OneTrace\Connector\Model\Config;

/**
 * /cdp-sw.js from the store root: the Web Push service worker must come from the store's own domain.
 */
class ServiceWorkerRouter implements RouterInterface
{
    public function __construct(
        private readonly ActionFactory $actionFactory,
        private readonly Config $config,
        private readonly StoreManagerInterface $storeManager,
    ) {
    }

    public function match(RequestInterface $request): ?ActionInterface
    {
        $storeId = (int) $this->storeManager->getStore()->getId();

        if (trim((string) $request->getPathInfo(), '/') !== 'cdp-sw.js' || !$this->config->push($storeId) || $this->config->url($storeId) === '') {
            return null;
        }

        return $this->actionFactory->create(ServiceWorker::class);
    }
}
