<?php

declare(strict_types=1);

namespace OneTrace\Connector\Controller;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Store\Model\StoreManagerInterface;
use OneTrace\Connector\Model\Config;

class ServiceWorker implements HttpGetActionInterface
{
    public function __construct(
        private readonly RawFactory $rawFactory,
        private readonly Config $config,
        private readonly StoreManagerInterface $storeManager,
    ) {
    }

    public function execute(): Raw
    {
        $url = $this->config->url((int) $this->storeManager->getStore()->getId()) . '/tracker/cdp-sw.js';

        return $this->rawFactory->create()
            ->setHeader('Content-Type', 'application/javascript; charset=utf-8', true)
            ->setHeader('Service-Worker-Allowed', '/', true)
            ->setHeader('Cache-Control', 'max-age=3600', true)
            ->setContents('importScripts(' . json_encode($url, JSON_UNESCAPED_SLASHES) . ");\n");
    }
}
