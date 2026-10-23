<?php

declare(strict_types=1);

namespace OneTrace\Connector\Model;

use Magento\Framework\App\CacheInterface;
use Psr\Log\LoggerInterface;

/**
 * The OneTrace search widget ("OneTrace search widget" setting): the tracker draws the search layer under the search
 * box and the whole results page — products, facets, sorting, infinite scroll, "Add to cart". It works when the
 * setting is on and the platform says the widget of the website is on (Site → Search). The answer of the platform is
 * kept in the Magento cache for LIFETIME seconds, a failure for FAILURE_LIFETIME seconds; any error or a widget
 * turned off keeps the store's own results page. Saving the module settings cleans it.
 */
class SearchWidget
{
    public const LIFETIME = 600;

    public const FAILURE_LIFETIME = 60;

    public const CACHE_TAG = 'ONETRACE_SEARCH_WIDGET';

    /** @var array<string, bool> */
    private array $loaded = [];

    public function __construct(
        private readonly Config $config,
        private readonly Connection $connection,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** The setting is on: the results page may change with the answer of the platform. */
    public function configured(int $storeId): bool
    {
        return $this->config->storefront($storeId) && $this->config->enabled('tracker', $storeId) && $this->config->enabled('search_widget', $storeId);
    }

    /** The widget draws the results page of the store view. */
    public function active(int $storeId): bool
    {
        if (!$this->configured($storeId)) {
            return false;
        }

        $key = 'onetrace_search_widget_' . sha1($storeId . '|' . $this->config->url($storeId) . '|' . $this->config->writeKey($storeId));

        if (array_key_exists($key, $this->loaded)) {
            return $this->loaded[$key];
        }

        $cached = $this->cache->load($key);

        if ($cached === '1' || $cached === '0') {
            return $this->loaded[$key] = $cached === '1';
        }

        try {
            $enabled = ($this->connection->client($storeId, 'search')->search()->widget($this->config->locale($storeId) ?: null)['enabled'] ?? false) === true;
            $lifetime = self::LIFETIME;
        } catch (\Throwable $error) {
            $this->logger->warning('OneTrace: search widget settings: ' . $error->getMessage());
            $enabled = false;
            $lifetime = self::FAILURE_LIFETIME;
        }

        $this->cache->save($enabled ? '1' : '0', $key, [self::CACHE_TAG], $lifetime);

        return $this->loaded[$key] = $enabled;
    }

    public function clean(): void
    {
        $this->loaded = [];
        $this->cache->clean([self::CACHE_TAG]);
    }
}
