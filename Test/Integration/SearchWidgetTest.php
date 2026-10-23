<?php

declare(strict_types=1);

namespace OneTrace\Connector\Test\Integration;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Area;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\Response\Http as HttpResponse;
use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Framework\View\LayoutInterface;
use Magento\Store\Model\App\Emulation;
use OneTrace\Connector\Block\SearchResults;
use OneTrace\Connector\Block\Tracker;
use OneTrace\Connector\Controller\Cart\Add;
use OneTrace\Connector\Model\SearchWidget;
use OneTrace\Connector\Observer\SearchWidgetPage;
use OneTrace\Connector\Test\TestCase;
use OneTrace\Http\Request;
use OneTrace\Http\Response;

final class SearchWidgetTest extends TestCase
{
    protected const SETTINGS = parent::SETTINGS + ['onetrace/parts/search' => 1, 'onetrace/parts/search_widget' => 1];

    /** @var list<string> */
    private array $urls = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->om->get(SearchWidget::class)->clean();
    }

    protected function tearDown(): void
    {
        $this->om->get(\Magento\Config\Model\ResourceModel\Config::class)->deleteConfig('onetrace/parts/search_widget');
        $this->reinit();
        $this->om->get(SearchWidget::class)->clean();
    }

    /**
     * The platform: the widget settings with $widget (a status code or the answer), the search API with products.
     *
     * @param int|array<string, mixed> $widget
     */
    private function respond(int|array $widget): void
    {
        $urls = &$this->urls;
        $this->platform->responder = static function (Request $request) use ($widget, &$urls): ?Response {
            if (!str_contains($request->getUrl(), '/api/v1/search')) {
                return null;
            }

            $urls[] = $request->getUrl();

            if (str_contains($request->getUrl(), '/api/v1/search/widget')) {
                return is_int($widget)
                    ? new Response($widget, ['content-type' => 'application/json'], '{"message":"error"}')
                    : new Response(200, ['content-type' => 'application/json'], (string) json_encode($widget));
            }

            return new Response(200, ['content-type' => 'application/json'], '{"request_id":"r","total":0,"items":[],"products":[],"categories":[],"queries":[],"redirect":"https://shop.example.com/rule"}');
        };
    }

    /**
     * The quick search results page of store view 1: its layout with the updates loaded through the observer. Blocks of
     * the whole page need the frontend DI of a real request: the HTML of the page is checked by tests/env/smoke.sh.
     */
    private function resultsPage(): LayoutInterface
    {
        return $this->storefront(function (): LayoutInterface {
            $layout = $this->om->create(LayoutInterface::class);
            $layout->getUpdate()->addHandle(['default', 'catalogsearch_result_index']);
            $this->om->get(SearchWidgetPage::class)->execute($this->event('layout_load_before', $layout));
            $layout->getUpdate()->load();

            // The layout must live on: destroying it empties its updates.
            return $layout;
        });
    }

    private function event(string $name, LayoutInterface $layout): Observer
    {
        $event = new Event(['layout' => $layout, 'full_action_name' => 'catalogsearch_result_index']);
        $event->setName($name);

        return new Observer(['event' => $event]);
    }

    private function storefront(callable $callback): mixed
    {
        $emulation = $this->om->get(Emulation::class);
        $emulation->startEnvironmentEmulation(1, Area::AREA_FRONTEND, true);

        try {
            return $this->om->get(\Magento\Framework\App\State::class)->emulateAreaCode(Area::AREA_FRONTEND, $callback);
        } finally {
            $emulation->stopEnvironmentEmulation();
        }
    }

    /**
     * The search plugins on the results page: whether Magento's search and the redirect rules asked the platform.
     */
    private function storeSearch(string $text): void
    {
        $this->storefront(function () use ($text): void {
            $filterBuilder = $this->om->get(\Magento\Framework\Api\FilterBuilder::class);
            $builder = $this->om->create(\Magento\Framework\Api\Search\SearchCriteriaBuilder::class);
            $builder->addFilter($filterBuilder->setField('search_term')->setValue($text)->create());
            $criteria = $builder->create();
            $criteria->setRequestName('quick_search_container');
            $criteria->setSortOrders(['relevance' => 'DESC']);
            $native = $this->om->get(\Magento\Search\Api\SearchInterface::class);
            $this->om->get(\OneTrace\Connector\Plugin\Search::class)->aroundSearch($native, static fn ($criteria) => $native->search($criteria), $criteria);

            $request = $this->om->create(HttpRequest::class);
            $request->setParams(['q' => $text]);
            $controller = $this->om->create(\Magento\CatalogSearch\Controller\Result\Index::class);
            $this->om->create(\OneTrace\Connector\Plugin\SearchRedirect::class, ['request' => $request])->aroundExecute($controller, static fn () => null);
        });
    }

    public function testTheWidgetDrawsTheResultsPageWhenItIsOnInOneTrace(): void
    {
        $this->respond(['enabled' => true, 'cart_url' => null]);
        $layout = $this->resultsPage();
        $update = $layout->getUpdate();

        self::assertContains(SearchWidgetPage::HANDLE, $update->getHandles());
        self::assertStringContainsString('<referenceBlock name="search.result" remove="true"/>', $update->asString(), 'Magento\'s product list is not built.');
        self::assertStringContainsString('<referenceBlock name="catalogsearch.leftnav" remove="true"/>', $update->asString(), 'Nor the layered navigation.');
        self::assertStringContainsString('name="onetrace.search.results"', $update->asString());
        self::assertSame('1column', $update->getPageLayout(), 'The widget has its own filters column.');

        // The storefront URLs: the object manager of the tests has the admin ones.
        $context = $this->om->create(\Magento\Framework\View\Element\Template\Context::class, ['urlBuilder' => $this->om->get(\Magento\Framework\Url::class)]);
        $block = $this->storefront(fn () => $this->om->get(LayoutInterface::class)->createBlock(SearchResults::class, '', ['context' => $context]));
        $html = $this->storefront(static fn (): string => $block->toHtml());
        self::assertMatchesRegularExpression('#<div data-cdp-search-results data-query-param="q" data-cart-url="http://[^"]+/onetrace/cart/add/id/\{id\}/"></div>#', $html);
        self::assertStringContainsString("window.addEventListener('cdp:add-to-cart'", $html);
        self::assertStringContainsString("d.reload(['cart'],true)", $html);

        // The search box keeps data-cdp-search, also without "Product search".
        $writer = $this->om->get(\Magento\Config\Model\ResourceModel\Config::class);
        $writer->saveConfig('onetrace/parts/search', '0');
        $this->reinit();
        self::assertTrue($this->storefront(fn (): bool => $this->om->get(LayoutInterface::class)->createBlock(Tracker::class)->searchEnabled()));
        $writer->saveConfig('onetrace/parts/search', '1');
        $this->reinit();

        // The tracker searches and follows the redirect rules: the server does not ask the platform.
        $this->storeSearch('dress');
        $this->flush();

        self::assertSame(['/api/v1/search/widget'], array_map(static fn (string $url): string => (string) parse_url($url, PHP_URL_PATH), $this->urls), 'The widget state is asked once and cached.');
        self::assertStringContainsString('language=en_US', $this->urls[0]);
        self::assertCount(0, $this->platform->events('search'));
    }

    public function testKeepsTheStoreResultsPageWhenTheWidgetIsOffOrThePlatformFails(): void
    {
        foreach ([['enabled' => false], 500, 402] as $answer) {
            $this->om->get(SearchWidget::class)->clean();
            $this->urls = [];
            $this->respond($answer);
            $layout = $this->resultsPage();
            $update = $layout->getUpdate();

            self::assertNotContains(SearchWidgetPage::HANDLE, $update->getHandles(), (string) json_encode($answer));
            self::assertStringNotContainsString('onetrace.search.results', $update->asString());
            self::assertSame('2columns-left', $update->getPageLayout());

            // Magento's page with the products and the redirect rules of "Product search", as before.
            $this->storeSearch('dress');
            self::assertSame(['/api/v1/search/widget', '/api/v1/search', '/api/v1/search/suggest'], array_map(static fn (string $url): string => (string) parse_url($url, PHP_URL_PATH), $this->urls));
        }

        // The setting off: the platform is not asked about the widget.
        $this->om->get(\Magento\Config\Model\ResourceModel\Config::class)->saveConfig('onetrace/parts/search_widget', '0');
        $this->reinit();
        $this->om->get(SearchWidget::class)->clean();
        $this->urls = [];

        self::assertNotContains(SearchWidgetPage::HANDLE, $this->resultsPage()->getUpdate()->getHandles());
        self::assertCount(0, $this->urls);
    }

    public function testTheFullPageCacheKeepsTheResultsPageNoLongerThanTheWidgetState(): void
    {
        $response = $this->om->get(HttpResponse::class);
        $layout = $this->om->create(LayoutInterface::class);
        $response->setPublicHeaders(86400);
        $this->storefront(fn () => $this->om->get(SearchWidgetPage::class)->execute($this->event('layout_generate_blocks_after', $layout)));

        self::assertStringContainsString('max-age=600', $response->getHeader('Cache-Control')->getFieldValue());

        $response->setNoCacheHeaders();
        $this->storefront(fn () => $this->om->get(SearchWidgetPage::class)->execute($this->event('layout_generate_blocks_after', $layout)));

        self::assertStringContainsString('no-store', $response->getHeader('Cache-Control')->getFieldValue(), 'Pages that are not cached stay so.');

        $this->om->get(\Magento\Config\Model\ResourceModel\Config::class)->saveConfig('onetrace/parts/search_widget', '0');
        $this->reinit();
        $response->setPublicHeaders(86400);
        $this->storefront(fn () => $this->om->get(SearchWidgetPage::class)->execute($this->event('layout_generate_blocks_after', $layout)));

        self::assertStringContainsString('max-age=86400', $response->getHeader('Cache-Control')->getFieldValue());
    }

    /**
     * POST onetrace/cart/add/id/{id} of the widget on store view 1: the status code and the JSON answer.
     *
     * @param array<string, string> $params
     *
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function addToCart(int|string $id, array $params = [], bool $ajax = true): array
    {
        return $this->storefront(function () use ($id, $params, $ajax): array {
            $request = $this->om->create(HttpRequest::class);
            $request->setMethod('POST');
            $request->setParams(['id' => (string) $id] + $params + ['quantity' => '1', 'form_key' => $this->om->get(FormKey::class)->getFormKey()]);

            if ($ajax) {
                $request->getHeaders()->addHeaderLine('X-Requested-With', 'XMLHttpRequest');
            }

            $result = $this->om->create(Add::class, ['request' => $request])->execute();
            $response = $this->om->create(HttpResponse::class);
            $result->renderResult($response);

            return [$response->getHttpResponseCode(), (array) json_decode((string) $response->getBody(), true)];
        });
    }

    private function inCart(ProductInterface $product): int
    {
        $quantity = 0;

        foreach ($this->om->get(CheckoutSession::class)->getQuote()->getAllVisibleItems() as $item) {
            if ((int) $item->getProductId() === (int) $product->getId()) {
                $quantity += (int) $item->getQty();
            }
        }

        return $quantity;
    }

    public function testAddsASimpleProductToTheCart(): void
    {
        $product = $this->product(['name' => 'Widget Socks']);

        [$status, $answer] = $this->addToCart((int) $product->getId(), ['quantity' => '2']);

        self::assertSame(200, $status);
        self::assertTrue($answer['ok']);
        self::assertSame(2, $this->inCart($product));
        self::assertSame((int) $this->om->get(\Magento\Checkout\Model\Cart::class)->getSummaryQty(), $answer['count']);
        self::assertGreaterThanOrEqual(2, $answer['count']);
    }

    public function testSendsProductsThatNeedAChoiceOrCannotBeBoughtToTheirPage(): void
    {
        $configurable = $this->om->create(ProductInterface::class);
        $sku = 'onetrace-conf-' . bin2hex(random_bytes(4));
        $configurable->setTypeId('configurable')->setAttributeSetId(4)->setSku($sku)->setUrlKey($sku)->setName('Widget Dress')
            ->setVisibility(Visibility::VISIBILITY_BOTH)->setStatus(Status::STATUS_ENABLED)->setWebsiteIds([1])
            ->setStockData(['use_config_manage_stock' => 1, 'is_in_stock' => 1]);
        $configurable = $this->admin(fn () => $this->om->get(ProductRepositoryInterface::class)->save($configurable));
        $soldOut = $this->product(['stock' => ['use_config_manage_stock' => 1, 'qty' => 0, 'is_in_stock' => 0]]);
        $tooMany = $this->product(['stock' => ['use_config_manage_stock' => 1, 'qty' => 1, 'is_in_stock' => 1]]);

        foreach ([[$configurable, '1'], [$soldOut, '1'], [$tooMany, '5']] as [$product, $quantity]) {
            [$status, $answer] = $this->addToCart((int) $product->getId(), ['quantity' => $quantity]);

            self::assertSame(200, $status);
            self::assertFalse($answer['ok']);
            self::assertStringContainsString('/' . $product->getUrlKey() . '.html', (string) $answer['redirect']);
            self::assertSame(0, $this->inCart($product));
        }
    }

    public function testRefusesRequestsWithoutTheHeaderOrTheFormKeyAndUnknownProducts(): void
    {
        $product = $this->product();

        self::assertSame(400, $this->addToCart((int) $product->getId(), [], false)[0], 'A form of another website.');
        self::assertSame(403, $this->addToCart((int) $product->getId(), ['form_key' => 'wrong'])[0]);
        self::assertSame(0, $this->inCart($product));

        [$status, $answer] = $this->addToCart(999999999);

        self::assertSame(404, $status);
        self::assertFalse($answer['ok']);
        self::assertSame($this->om->get(\Magento\Store\Model\StoreManagerInterface::class)->getStore(1)->getBaseUrl(), $answer['redirect']);
        self::assertSame(404, $this->addToCart('abc')[0]);
    }
}
