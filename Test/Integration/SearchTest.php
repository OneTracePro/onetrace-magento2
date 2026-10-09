<?php

declare(strict_types=1);

namespace OneTrace\Connector\Test\Integration;

use Magento\Framework\Api\FilterBuilder;
use Magento\Framework\Api\Search\SearchCriteriaBuilder;
use Magento\Framework\Api\Search\SearchResultInterface;
use Magento\Search\Api\SearchInterface;
use Magento\Store\Model\App\Emulation;
use OneTrace\Connector\Plugin\Search;
use OneTrace\Connector\Test\TestCase;
use OneTrace\Http\Request;
use OneTrace\Http\Response;

final class SearchTest extends TestCase
{
    protected const SETTINGS = parent::SETTINGS + ['onetrace/parts/search' => 1];

    /** @var list<string> */
    private array $urls = [];

    /**
     * @param array<string, mixed> $body
     */
    private function respond(int $status, array $body = []): void
    {
        $urls = &$this->urls;
        $this->platform->responder = static function (Request $request) use ($status, $body, &$urls): ?Response {
            if (!str_contains($request->getUrl(), '/api/v1/search')) {
                return null;
            }

            $urls[] = $request->getUrl();

            return new Response($status, ['content-type' => 'application/json'], (string) json_encode($body));
        };
    }

    /**
     * The quick search of the storefront (the results page) through the plugin.
     *
     * @param array<string, mixed> $filters
     * @param array<string, string> $sort
     */
    private function search(array $filters, int $page = 0, int $size = 12, array $sort = []): SearchResultInterface
    {
        $filterBuilder = $this->om->get(FilterBuilder::class);
        $builder = $this->om->create(SearchCriteriaBuilder::class);

        // As on the results page: the visibility, the categories of the layered navigation and the price algorithm.
        foreach ($filters + ['visibility' => [3, 4], 'category_ids_to_aggregate' => ['3', '6'], 'price_dynamic_algorithm' => 'auto'] as $field => $value) {
            $builder->addFilter($filterBuilder->setField($field)->setValue($value)->create());
        }

        $criteria = $builder->create();
        $criteria->setRequestName('quick_search_container');
        $criteria->setCurrentPage($page);
        $criteria->setPageSize($size);
        $criteria->setSortOrders($sort + ['relevance' => 'DESC', 'entity_id' => 'ASC']);
        $emulation = $this->om->get(Emulation::class);
        $emulation->startEnvironmentEmulation(1, \Magento\Framework\App\Area::AREA_FRONTEND, true);

        try {
            $native = $this->om->get(SearchInterface::class);

            return $this->om->get(Search::class)->aroundSearch($native, static fn ($criteria) => $native->search($criteria), $criteria);
        } finally {
            $emulation->stopEnvironmentEmulation();
        }
    }

    /**
     * @return list<int>
     */
    private static function ids(SearchResultInterface $result): array
    {
        return array_map(static fn ($document): int => (int) $document->getId(), $result->getItems());
    }

    public function testShowsProductsOfThePlatformInItsOrderAndCountsTheSearch(): void
    {
        $dress = $this->product(['name' => 'Linen Dress']);
        $skirt = $this->product(['name' => 'Linen Skirt']);
        $_COOKIE['cdp_aid'] = 'browser-1';
        $this->respond(200, ['request_id' => 'req-1', 'total' => 7, 'items' => [['id' => (string) $skirt->getId()], ['id' => (string) $dress->getId()]]]);

        // A typo: Magento's own search would find nothing.
        $result = $this->search(['search_term' => 'lnen dres'], 0, 2);
        $this->flush();
        $search = $this->platform->events('search');

        self::assertSame([(int) $skirt->getId(), (int) $dress->getId()], self::ids($result));
        self::assertSame(7, $result->getTotalCount());
        self::assertStringContainsString('q=lnen%20dres', $this->urls[0]);
        self::assertStringContainsString('page=1', $this->urls[0]);
        self::assertStringContainsString('per_page=2', $this->urls[0]);
        self::assertStringContainsString('anonymousId=browser-1', $this->urls[0]);
        self::assertStringContainsString('language=en_US', $this->urls[0]);
        self::assertCount(1, $search);
        self::assertSame(['query' => 'lnen dres', 'results' => 7, 'request_id' => 'req-1'], $search[0]['properties']);
        self::assertContract($search[0]);

        // The second page with the price sorting and a category of the layered navigation: no new event.
        $this->search(['search_term' => 'lnen dres', 'category_ids' => '5', 'price.from' => '10', 'price.to' => '50'], 1, 2, ['price' => 'DESC']);
        $this->flush();

        self::assertStringContainsString('page=2', $this->urls[1]);
        self::assertStringContainsString('sort=price_desc', $this->urls[1]);
        self::assertStringContainsString('category=5', $this->urls[1]);
        self::assertStringContainsString('price_min=10', $this->urls[1]);
        self::assertStringContainsString('price_max=50', $this->urls[1]);
        self::assertCount(1, $this->platform->events('search'));
    }

    public function testKeepsMagentosSearchOnErrorsAndForFiltersThePlatformDoesNotHave(): void
    {
        $dress = $this->product(['name' => 'Onetrace Linen Dress ' . bin2hex(random_bytes(3))]);
        $this->respond(500, ['message' => 'Server error']);

        self::assertContains((int) $dress->getId(), self::ids($this->search(['search_term' => (string) $dress->getName()])));

        $this->search(['search_term' => 'dress', 'color' => '5']);
        $this->search(['search_term' => 'dress'], 0, 12, ['name' => 'ASC']);

        self::assertCount(1, $this->urls, 'Only the first search asked the platform.');
    }
}
