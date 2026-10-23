<?php

declare(strict_types=1);

namespace OneTrace\Connector\Plugin;

use Magento\CatalogSearch\Controller\Result\Index;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Store\Model\StoreManagerInterface;
use OneTrace\Connector\Model\Config;
use OneTrace\Connector\Model\Connection;
use OneTrace\Connector\Model\SearchWidget;
use Psr\Log\LoggerInterface;

/**
 * Redirect rules of the platform (Site → Search, e.g. "delivery" → the delivery page) on the quick search results page:
 * before the page is built, a short suggestion request tells whether the query has a redirect. No rule, a later page
 * of the results or any API error — the page is built as usual. The OneTrace search widget follows the rules itself.
 */
class SearchRedirect
{
    public function __construct(
        private readonly Config $config,
        private readonly Connection $connection,
        private readonly StoreManagerInterface $storeManager,
        private readonly RequestInterface $request,
        private readonly RedirectFactory $redirects,
        private readonly LoggerInterface $logger,
        private readonly SearchWidget $searchWidget,
    ) {
    }

    public function aroundExecute(Index $subject, callable $proceed): mixed
    {
        $url = $this->redirect();

        return $url !== null ? $this->redirects->create()->setUrl($url) : $proceed();
    }

    private function redirect(): ?string
    {
        $storeId = (int) $this->storeManager->getStore()->getId();
        $text = trim((string) $this->request->getParam('q'));

        if ($text === '' || (int) $this->request->getParam('p', 1) > 1 || !$this->config->storefront($storeId) || !$this->config->enabled('search', $storeId) || $this->searchWidget->active($storeId)) {
            return null;
        }

        try {
            $result = $this->connection->client($storeId, 'search')->search()->suggest($text, ['limit' => 1, 'language' => $this->config->locale($storeId)]);
        } catch (\Throwable $e) {
            $this->logger->warning('OneTrace search redirect skipped: ' . $e->getMessage());

            return null;
        }

        $url = (string) ($result['redirect'] ?? '');

        return preg_match('#^https?://[^\s/]+#i', $url) === 1 ? $url : null;
    }
}
