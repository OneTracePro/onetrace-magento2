<?php

declare(strict_types=1);

namespace OneTrace\Connector\Plugin;

use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\Api\Search\DocumentFactory;
use Magento\Framework\Api\Search\SearchCriteriaInterface;
use Magento\Framework\Api\Search\SearchResultInterface;
use Magento\Search\Api\SearchInterface;
use Magento\Store\Model\StoreManagerInterface;
use OneTrace\Commerce\Customer;
use OneTrace\Connector\Model\Config;
use OneTrace\Connector\Model\Connection;
use OneTrace\Connector\Model\Queue;
use OneTrace\Connector\Model\SearchWidget;
use Psr\Log\LoggerInterface;

/**
 * Product search of the store from OneTrace ("Product search" setting): the quick search of the storefront keeps
 * Magento's results page and layered navigation, but its products, their order and their number come from the
 * platform (word forms, typos, the wrong keyboard layout, the visitor's interests). Filters by other attributes,
 * sorting by name and any API error keep Magento's own search. With the OneTrace search widget the tracker draws the
 * results page and sends the search event itself: the platform is not asked here.
 */
class Search
{
    private const REQUEST = 'quick_search_container';

    /**
     * Filters of the quick search the platform understands or that only shape the layered navigation
     * (category_ids_to_aggregate, price_dynamic_algorithm); any other one keeps Magento's search.
     */
    private const FILTERS = ['search_term', 'visibility', 'category_ids', 'category_ids_to_aggregate', 'price.from', 'price.to', 'price_dynamic_algorithm'];

    private const MAX_PER_PAGE = 48;

    public function __construct(
        private readonly Config $config,
        private readonly Connection $connection,
        private readonly Queue $queue,
        private readonly StoreManagerInterface $storeManager,
        private readonly CustomerSession $session,
        private readonly DocumentFactory $documents,
        private readonly LoggerInterface $logger,
        private readonly SearchWidget $searchWidget,
    ) {
    }

    public function aroundSearch(SearchInterface $subject, callable $proceed, SearchCriteriaInterface $criteria): SearchResultInterface
    {
        /** @var SearchResultInterface $native */
        $native = $proceed($criteria);
        $storeId = (int) $this->storeManager->getStore()->getId();

        if ($criteria->getRequestName() !== self::REQUEST || !$this->config->storefront($storeId) || !$this->config->enabled('search', $storeId) || $this->searchWidget->active($storeId)) {
            return $native;
        }

        $params = $this->params($criteria);

        if ($params === null || ($params['q'] ?? '') === '') {
            return $native;
        }

        try {
            $text = (string) $params['q'];
            unset($params['q']);
            $result = $this->connection->client($storeId, 'search')->search()->products($text, $params + array_filter([
                'anonymousId' => $this->connection->anonymousId(),
                // The store view language: names and links of the catalog translations.
                'language' => $this->config->locale($storeId),
            ]));
        } catch (\Throwable $e) {
            $this->logger->warning('OneTrace search fell back to Magento: ' . $e->getMessage());

            return $native;
        }

        $documents = [];

        foreach ((array) ($result['items'] ?? []) as $item) {
            if (is_array($item) && ctype_digit((string) ($item['id'] ?? ''))) {
                $documents[] = $this->documents->create()->setId((int) $item['id']);
            }
        }

        if ((int) $params['page'] === 1) {
            $this->track($storeId, $text, (int) ($result['total'] ?? 0), (string) ($result['request_id'] ?? ''));
        }

        // Layered navigation keeps the aggregations of Magento's search.
        return $native->setItems($documents)->setTotalCount((int) ($result['total'] ?? 0));
    }

    /**
     * Parameters of the platform search from the criteria; null — a filter or a sorting the platform does not have.
     *
     * @return array<string, mixed>|null
     */
    private function params(SearchCriteriaInterface $criteria): ?array
    {
        $perPage = (int) $criteria->getPageSize();
        $params = [
            'page' => (int) $criteria->getCurrentPage() + 1,
            'per_page' => $perPage > 0 ? min(self::MAX_PER_PAGE, $perPage) : 12,
        ];

        foreach ($criteria->getFilterGroups() as $group) {
            foreach ($group->getFilters() as $filter) {
                $field = (string) $filter->getField();
                $value = $filter->getValue();

                if (!in_array($field, self::FILTERS, true)) {
                    return null;
                }

                if ($field === 'search_term') {
                    $params['q'] = trim((string) $value);
                } elseif ($field === 'category_ids') {
                    $ids = array_values((array) $value);

                    if (count($ids) !== 1) {
                        return null;
                    }

                    $params['category'] = (string) $ids[0];
                } elseif ($field === 'price.from' || $field === 'price.to') {
                    $params[$field === 'price.from' ? 'price_min' : 'price_max'] = (float) $value;
                }
            }
        }

        foreach ((array) $criteria->getSortOrders() as $field => $direction) {
            // Sort orders come as "field" => "ASC|DESC" or as SortOrder objects; entity_id is the tie-breaker Magento
            // adds to every sorting.
            if ($direction instanceof \Magento\Framework\Api\SortOrder) {
                $field = (string) $direction->getField();
                $direction = (string) $direction->getDirection();
            }

            if ($field === 'price') {
                $params['sort'] = strtoupper((string) $direction) === 'DESC' ? 'price_desc' : 'price_asc';
            } elseif (!in_array($field, ['relevance', 'position', '_score', 'entity_id'], true)) {
                return null;
            }
        }

        return $params;
    }

    /**
     * The search event of the results page from the server: counted with page caching and blockers too.
     */
    private function track(int $storeId, string $text, int $results, string $requestId): void
    {
        $customerId = $this->session->isLoggedIn() ? (string) $this->session->getCustomerId() : null;
        $anonymousId = $this->connection->anonymousId();

        if ($customerId === null && $anonymousId === null) {
            return;
        }

        $this->queue->event($storeId, $this->connection->messages($storeId)->search(new Customer($customerId, $anonymousId), $text, $results, $requestId !== '' ? $requestId : null));
    }
}
