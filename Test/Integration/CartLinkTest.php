<?php

declare(strict_types=1);

namespace OneTrace\Connector\Test\Integration;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductAttributeManagementInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Eav\Attribute;
use Magento\Checkout\Model\Cart;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\ConfigurableProduct\Helper\Product\Options\Factory as ConfigurableOptions;
use Magento\Framework\App\Area;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\Response\Http as HttpResponse;
use Magento\Framework\Stdlib\CookieManagerInterface;
use Magento\Quote\Model\QuoteFactory;
use Magento\Store\Model\App\Emulation;
use Magento\Store\Model\StoreManagerInterface;
use OneTrace\Connector\Controller\CartLink;
use OneTrace\Connector\Controller\CartLinkRouter;
use OneTrace\Connector\Model\CartLink as CartLinks;
use OneTrace\Connector\Test\TestCase;

final class CartLinkTest extends TestCase
{
    private const SIZE = 'onetrace_size';

    /** @var list<string> names of the cookies the cart link set */
    private array $cookies = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Every test starts with an empty cart of a new guest.
        $this->storefront(function (): void {
            $this->om->get(CheckoutSession::class)->clearQuote();
            $this->om->get(Cart::class)->unsetData('quote');
        });
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
     * GET {uri} of a cart link on store view 1: the status code and the headers of the answer.
     *
     * @return array{0: int, 1: string, 2: string}
     */
    private function follow(string $uri): array
    {
        return $this->storefront(function () use ($uri): array {
            $request = $this->om->create(HttpRequest::class);
            $request->setMethod('GET');
            $request->setRequestUri($uri);
            $cookies = $this->createStub(CookieManagerInterface::class);
            $cookies->method('setPublicCookie')->willReturnCallback(function (string $name): void {
                $this->cookies[] = $name;
            });

            // The storefront URLs: the object manager of the tests has the admin ones.
            $action = $this->om->create(CartLink::class, ['request' => $request, 'url' => $this->om->get(\Magento\Framework\Url::class), 'cookies' => $cookies]);
            $response = $this->om->create(HttpResponse::class);
            $action->execute()->renderResult($response);

            return [$response->getHttpResponseCode(), (string) $response->getHeader('Location')->getFieldValue(), (string) $response->getHeader('Cache-Control')->getFieldValue()];
        });
    }

    private function base(): string
    {
        return (string) $this->om->get(StoreManagerInterface::class)->getStore(1)->getBaseUrl();
    }

    private function origin(): string
    {
        $base = (array) parse_url($this->base());

        return $base['scheme'] . '://' . $base['host'] . (isset($base['port']) ? ':' . $base['port'] : '');
    }

    /**
     * The saved cart of the visitor: product id of the cart line (the configurable product for a variant) =>
     * [quantity, product id of the variant or null]. Its totals count the same lines (the cart page shows them).
     *
     * @return array<int, array{0: int, 1: int|null}>
     */
    private function cart(): array
    {
        return $this->storefront(function (): array {
            $quoteId = (int) $this->om->get(CheckoutSession::class)->getQuoteId();
            $quote = $this->om->create(QuoteFactory::class)->create()->loadActive($quoteId);
            $lines = [];
            $quantity = 0;

            foreach ($quoteId > 0 ? $quote->getAllVisibleItems() : [] as $item) {
                $children = $item->getChildren();
                $lines[(int) $item->getProductId()] = [(int) $item->getQty(), $children !== [] ? (int) reset($children)->getProductId() : null];
                $quantity += (int) $item->getQty();
            }

            self::assertSame(count($lines), (int) $quote->getItemsCount());
            self::assertSame($quantity, (int) $quote->getItemsQty());

            return $lines;
        });
    }

    /**
     * A configurable dress with sizes S and M (simple products not visible on their own); M is sold out.
     *
     * @return array{0: ProductInterface, 1: ProductInterface, 2: ProductInterface}
     */
    private function configurable(): array
    {
        $attribute = $this->sizeAttribute();
        $options = [];

        foreach ($attribute->getSource()->getAllOptions(false) as $option) {
            $options[(string) $option['label']] = (int) $option['value'];
        }

        $small = $this->product(['name' => 'Dress S', 'visibility' => Visibility::VISIBILITY_NOT_VISIBLE, 'attributes' => [self::SIZE => $options['S']]]);
        $medium = $this->product(['name' => 'Dress M', 'visibility' => Visibility::VISIBILITY_NOT_VISIBLE, 'attributes' => [self::SIZE => $options['M']], 'stock' => ['use_config_manage_stock' => 1, 'qty' => 0, 'is_in_stock' => 0]]);
        $values = array_map(static fn (string $label): array => ['label' => $label, 'attribute_id' => $attribute->getId(), 'value_index' => $options[$label]], ['S', 'M']);
        $configurableOptions = $this->om->get(ConfigurableOptions::class)->create([['attribute_id' => $attribute->getId(), 'code' => self::SIZE, 'label' => 'Size', 'position' => '0', 'values' => $values]]);

        $parent = $this->om->create(ProductInterface::class);
        $sku = 'onetrace-dress-' . bin2hex(random_bytes(4));
        $parent->setTypeId('configurable')->setAttributeSetId(4)->setSku($sku)->setUrlKey($sku)->setName('Dress')
            ->setVisibility(Visibility::VISIBILITY_BOTH)->setStatus(Status::STATUS_ENABLED)->setWebsiteIds([1])
            ->setStockData(['use_config_manage_stock' => 1, 'is_in_stock' => 1]);
        $extension = $parent->getExtensionAttributes();
        $extension->setConfigurableProductOptions($configurableOptions);
        $extension->setConfigurableProductLinks([(int) $small->getId(), (int) $medium->getId()]);
        $parent->setExtensionAttributes($extension);
        $parent = $this->admin(fn () => $this->om->get(ProductRepositoryInterface::class)->save($parent));

        return [$parent, $small, $medium];
    }

    /** The select attribute of the configurable products, made once in the default attribute set. */
    private function sizeAttribute(): Attribute
    {
        $attribute = $this->om->create(Attribute::class)->loadByCode('catalog_product', self::SIZE);

        if (!$attribute->getId()) {
            $attribute->setData([
                'attribute_code' => self::SIZE,
                'entity_type_id' => (int) $this->om->get(\Magento\Eav\Model\Config::class)->getEntityType('catalog_product')->getId(),
                'frontend_input' => 'select',
                'backend_type' => 'int',
                'frontend_label' => ['Size'],
                'is_global' => 1,
                'is_user_defined' => 1,
                'is_required' => 0,
                'option' => ['value' => ['s' => ['S'], 'm' => ['M']], 'order' => ['s' => 1, 'm' => 2]],
            ]);
            $this->admin(static fn () => $attribute->save());
            $group = (int) $this->om->create(\Magento\Eav\Model\Entity\Attribute\Set::class)->load(4)->getDefaultGroupId();
            $this->om->get(ProductAttributeManagementInterface::class)->assign(4, $group, self::SIZE, 100);
            $this->om->get(\Magento\Framework\App\Cache\TypeListInterface::class)->cleanType('eav');
            $this->om->get(\Magento\Eav\Model\Config::class)->clear();
            $attribute = $this->om->create(Attribute::class)->loadByCode('catalog_product', self::SIZE);
        }

        return $attribute;
    }

    public function testFillsTheCartWithSeveralProductsAndAVariantAndKeepsTheUtmTags(): void
    {
        $socks = $this->product(['name' => 'Socks']);
        $cap = $this->product(['name' => 'Cap']);
        [$dress, $small] = $this->configurable();
        $cart = rawurlencode(sprintf('%d:2,%d:1,%d:3', $socks->getId(), $cap->getId(), $small->getId()));

        [$status, $location, $cache] = $this->follow('/women/dresses.html?utm_source=chatgpt&onetrace_cart=' . $cart . '&utm_medium=ai_assistant&utm_campaign=shopper_mcp');

        self::assertSame(302, $status);
        self::assertSame($this->base() . 'checkout/cart/?utm_source=chatgpt&utm_medium=ai_assistant&utm_campaign=shopper_mcp', $location);
        self::assertStringContainsString('no-store', $cache, 'Never kept by the full page cache or Varnish.');
        self::assertSame([(int) $socks->getId() => [2, null], (int) $cap->getId() => [1, null], (int) $dress->getId() => [3, (int) $small->getId()]], $this->cart(), 'The variant goes to the cart as the configurable product with its size.');
        self::assertSame(['section_data_clean', 'private_content_version'], $this->cookies, 'The mini cart reloads on the cart page.');

        // The utm tags may come first or last; the same link again adds up.
        [, $location] = $this->follow('/?onetrace_cart=' . $socks->getId() . ':1&utm_source=claude');

        self::assertSame($this->base() . 'checkout/cart/?utm_source=claude', $location);
        self::assertSame(3, $this->cart()[(int) $socks->getId()][0]);
    }

    public function testSkipsProductsThatCannotBeBoughtAndKeepsTheCartAsItWas(): void
    {
        $socks = $this->product(['name' => 'Socks']);
        $lastPair = $this->product(['name' => 'Last pair', 'stock' => ['use_config_manage_stock' => 1, 'qty' => 2, 'is_in_stock' => 1]]);
        $soldOut = $this->product(['stock' => ['use_config_manage_stock' => 1, 'qty' => 0, 'is_in_stock' => 0]]);
        $disabled = $this->product(['status' => Status::STATUS_DISABLED]);
        [$dress, , $medium] = $this->configurable();

        $this->follow('/?onetrace_cart=' . $lastPair->getId() . ':1');
        self::assertSame([(int) $lastPair->getId() => [1, null]], $this->cart());

        // More than in stock of a product already in the cart: Magento refuses it, the cart keeps the earlier line.
        $pairs = [$soldOut->getId() . ':1', $disabled->getId() . ':1', '999999999:1', $dress->getId() . ':1', $medium->getId() . ':1', $lastPair->getId() . ':5', $socks->getId() . ':1'];
        [$status, $location] = $this->follow('/?onetrace_cart=' . implode(',', $pairs) . '&utm_source=chatgpt');

        self::assertSame(302, $status);
        self::assertSame($this->base() . 'checkout/cart/?utm_source=chatgpt', $location);
        self::assertSame([(int) $lastPair->getId() => [1, null], (int) $socks->getId() => [1, null]], $this->cart(), 'Sold out, disabled, unknown, a configurable product without a size, a sold-out size and too many are skipped.');
    }

    public function testNothingAddedRedirectsToTheSamePageWithoutTheParameter(): void
    {
        $soldOut = $this->product(['stock' => ['use_config_manage_stock' => 1, 'qty' => 0, 'is_in_stock' => 0]]);

        [$status, $location, $cache] = $this->follow('/sneakers.html?utm_source=chatgpt&onetrace_cart=' . $soldOut->getId() . ':1,abc:1,:2,5&utm_medium=ai_assistant');

        self::assertSame(302, $status);
        self::assertSame($this->origin() . '/sneakers.html?utm_source=chatgpt&utm_medium=ai_assistant', $location);
        self::assertStringContainsString('no-store', $cache);
        self::assertSame([], $this->cart());
        self::assertSame([], $this->cookies);

        self::assertSame($this->origin() . '/', $this->follow('/?onetrace_cart=')[1]);
        self::assertSame($this->origin() . '/gear/bags.html?p=2', $this->follow('/gear/bags.html?onetrace_cart[]=1:1&p=2')[1], 'Any spelling PHP reads as the parameter is dropped.');
        self::assertSame($this->origin() . '/evil.example/', $this->follow('//evil.example/?onetrace.cart=x')[1], 'Always the store domain.');
    }

    public function testTakesTheFirstTwentyPairsAndClampsQuantities(): void
    {
        $links = $this->om->get(CartLinks::class);
        $many = implode(',', array_map(static fn (int $id): string => $id . ':1', range(1, 25)));

        self::assertSame(array_fill_keys(range(1, 20), 1), $links->parse($many));
        self::assertSame([3 => 1, 4 => 1, 5 => 99, 6 => 2, 9 => 99, 10 => 7], $links->parse('3:0,4:-2,5:500,6:1, 6 : 1 ,7:1.5,8:x,abc:1,12:3:4,9:99999999999999999999,10:007,:3,11'));
        self::assertSame([5 => 99], $links->parse('5:60,5:60'), 'Repeated ids add up to at most 99.');
        self::assertSame([], $links->parse(['1:1']));

        // In the cart: 0 becomes 1, 500 becomes 99.
        $socks = $this->product(['name' => 'Socks']);
        $cap = $this->product(['name' => 'Cap']);
        $this->follow('/?onetrace_cart=' . $socks->getId() . ':0,' . $cap->getId() . ':500');

        self::assertSame([(int) $socks->getId() => [1, null], (int) $cap->getId() => [99, null]], $this->cart());
    }

    public function testTheRouterTakesOnlyGetRequestsWithTheParameter(): void
    {
        $router = $this->om->create(CartLinkRouter::class);
        $request = fn (string $method, string $uri): HttpRequest => $this->om->create(HttpRequest::class)->setMethod($method)->setRequestUri($uri);

        self::assertInstanceOf(CartLink::class, $router->match($request('GET', '/women.html?utm_source=chatgpt&onetrace_cart=1:1')));
        self::assertNull($router->match($request('POST', '/women.html?onetrace_cart=1:1')));
        self::assertNull($router->match($request('GET', '/women.html?utm_source=chatgpt')));
    }
}
