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
 * turned off keeps the store's own results page. Saving the module settings cleans it. The same answer says the AI
 * search mode of the project (aiMode()) — for the AI results of the store's own search (Plugin\Search).
 */
class SearchWidget
{
    public const LIFETIME = 600;

    public const FAILURE_LIFETIME = 60;

    public const CACHE_TAG = 'ONETRACE_SEARCH_WIDGET';

    /** @var array<string, array{enabled: bool, ai: array<string, mixed>|null}> */
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
        return $this->configured($storeId) && $this->platform($storeId)['enabled'];
    }

    /**
     * The AI search mode of the project ({mode, answer}) for the AI results of the store's own search; null — off.
     *
     * @return array{mode: string, answer: bool}|null
     */
    public function aiMode(int $storeId): ?array
    {
        if (!$this->config->storefront($storeId)) {
            return null;
        }

        $ai = $this->platform($storeId)['ai'];

        return in_array($ai['mode'] ?? null, ['auto', 'toggle', 'always'], true) ? ['mode' => (string) $ai['mode'], 'answer' => (bool) ($ai['answer'] ?? true)] : null;
    }

    /**
     * GET /search/widget of the store view: the widget on and the AI search mode, cached.
     *
     * @return array{enabled: bool, ai: array<string, mixed>|null}
     */
    private function platform(int $storeId): array
    {
        $key = 'onetrace_search_widget_' . sha1($storeId . '|' . $this->config->url($storeId) . '|' . $this->config->writeKey($storeId));

        if (array_key_exists($key, $this->loaded)) {
            return $this->loaded[$key];
        }

        // An older version of the module kept "1" or "0": not JSON, asked again.
        $cached = json_decode((string) $this->cache->load($key), true);

        if (is_array($cached) && array_key_exists('ai', $cached)) {
            return $this->loaded[$key] = ['enabled' => ($cached['enabled'] ?? false) === true, 'ai' => is_array($cached['ai']) ? $cached['ai'] : null];
        }

        try {
            $widget = $this->connection->client($storeId, 'search')->search()->widget($this->config->locale($storeId) ?: null);
            $platform = ['enabled' => $widget['enabled'] === true, 'ai' => is_array($widget['ai'] ?? null) ? $widget['ai'] : null];
            $lifetime = self::LIFETIME;
        } catch (\Throwable $error) {
            $this->logger->warning('OneTrace: search widget settings: ' . $error->getMessage());
            $platform = ['enabled' => false, 'ai' => null];
            $lifetime = self::FAILURE_LIFETIME;
        }

        $this->cache->save((string) json_encode($platform), $key, [self::CACHE_TAG], $lifetime);

        return $this->loaded[$key] = $platform;
    }

    public function clean(): void
    {
        $this->loaded = [];
        $this->cache->clean([self::CACHE_TAG]);
    }
}
