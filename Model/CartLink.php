<?php

declare(strict_types=1);

namespace OneTrace\Connector\Model;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Type;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Checkout\Model\Cart;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable as ConfigurableType;
use Magento\ConfigurableProduct\Model\ResourceModel\Product\Type\Configurable;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Quote\Model\QuoteFactory;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Cart links of OneTrace (the create_cart tool the platform gives shoppers' AI assistants): `?onetrace_cart=ID:QTY,ID:QTY`
 * on any storefront page fills the visitor's own cart. The ids are those of the catalog the module uploads (product
 * ids); a simple product of a configurable one that is not visible on its own goes to the cart as the configurable
 * product with its attribute values, as in the catalog. The first 20 valid pairs count, quantities are clamped to 1–99;
 * unknown, disabled, sold-out products, products that need a choice and products the cart refuses are skipped.
 */
class CartLink
{
    public const PARAM = 'onetrace_cart';

    public const MAX_ITEMS = 20;

    public const MAX_QUANTITY = 99;

    /** Product types added without a choice on the product page. */
    private const TYPES = [Type::TYPE_SIMPLE, Type::TYPE_VIRTUAL];

    public function __construct(
        private readonly ProductRepositoryInterface $products,
        private readonly Configurable $configurable,
        private readonly StoreManagerInterface $storeManager,
        private readonly Cart $cart,
        private readonly CheckoutSession $session,
        private readonly QuoteFactory $quotes,
        private readonly EventManager $eventManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Products and quantities of the parameter (already decoded): comma-separated `id:quantity`, split on the last
     * colon. Repeated ids add up.
     *
     * @return array<int, int> product id => quantity
     */
    public function parse(mixed $value): array
    {
        $items = [];
        $pairs = 0;

        foreach (is_string($value) ? explode(',', $value) : [] as $pair) {
            $colon = strrpos($pair, ':');

            if ($colon === false) {
                continue;
            }

            $id = trim(substr($pair, 0, $colon));
            $quantity = trim(substr($pair, $colon + 1));

            if (preg_match('/^\d{1,10}$/', $id) !== 1 || (int) $id <= 0 || preg_match('/^-?\d+$/', $quantity) !== 1) {
                continue;
            }

            $items[(int) $id] = min(self::MAX_QUANTITY, ($items[(int) $id] ?? 0) + max(1, min(self::MAX_QUANTITY, (int) $quantity)));

            if (++$pairs >= self::MAX_ITEMS) {
                break;
            }
        }

        return $items;
    }

    /**
     * Adds the products to the cart of the current visitor (the checkout session: guests too), each one on its own:
     * one the cart refuses leaves the cart as it was before it. Returns how many products were added.
     *
     * @param array<int, int> $items product id => quantity
     */
    public function add(array $items, RequestInterface $request, ResponseInterface $response): int
    {
        $store = $this->storeManager->getStore();
        $candidates = [];

        foreach ($items as $id => $quantity) {
            $candidate = $this->candidate($id, $quantity, (int) $store->getId(), (int) $store->getWebsiteId());

            if ($candidate !== null) {
                $candidates[] = $candidate;
            }
        }

        if ($candidates === []) {
            return 0;
        }

        // A saved quote can be read again when the cart refuses a product (Magento drops the refused item, and an item of
        // the same product that was in the cart before with it).
        try {
            if (!$this->cart->getQuote()->getId()) {
                $this->save();
            }
        } catch (\Throwable $error) {
            $this->logger->error('OneTrace: cart link: ' . $error->getMessage());

            return 0;
        }

        $added = 0;

        foreach ($candidates as [$product, $buyRequest]) {
            try {
                $this->cart->addProduct($product, $buyRequest);
                $this->save();
                $added++;
                $this->eventManager->dispatch('checkout_cart_add_product_complete', ['product' => $product, 'request' => $request, 'response' => $response]);
            } catch (\Throwable $error) {
                if (!$error instanceof LocalizedException) {
                    $this->logger->error('OneTrace: cart link: ' . $error->getMessage());
                }

                $this->reload();
            }
        }

        return $added;
    }

    /**
     * The query string without the parameter, the other parameters as they came (utm tags and the like).
     */
    public static function withoutParam(string $query): string
    {
        $kept = [];

        foreach (explode('&', $query) as $part) {
            $parsed = [];
            parse_str($part, $parsed);

            if ($part !== '' && !array_key_exists(self::PARAM, $parsed)) {
                $kept[] = $part;
            }
        }

        return implode('&', $kept);
    }

    /**
     * The product to add and its buy request, or null when it cannot be added. Orders of the store MCP map catalog ids
     * to cart products the same way.
     *
     * @return array{0: Product, 1: array<string, mixed>}|null
     */
    public function candidate(int $id, int $quantity, int $storeId, int $websiteId): ?array
    {
        $product = $this->product($id, $storeId, $websiteId);

        if ($product === null || !in_array($product->getTypeId(), self::TYPES, true) || $product->getRequiredOptions() || !$product->isSalable()) {
            return null;
        }

        if ((int) $product->getVisibility() !== Visibility::VISIBILITY_NOT_VISIBLE) {
            return [$product, ['qty' => $quantity]];
        }

        $parentIds = $this->configurable->getParentIdsByChild($id);

        foreach ($parentIds as $parentId) {
            $parent = $this->product((int) $parentId, $storeId, $websiteId);

            if ($parent === null || $parent->getTypeId() !== ConfigurableType::TYPE_CODE || !$parent->isSalable()) {
                continue;
            }

            $attributes = $this->superAttributes($parent, $product);

            if ($attributes !== null) {
                return [$parent, ['qty' => $quantity, 'super_attribute' => $attributes]];
            }
        }

        // Not visible on its own and not a variant: bought as it is, like with Magento's own cart. A variant of
        // configurable products that cannot be bought is skipped.
        return $parentIds === [] ? [$product, ['qty' => $quantity]] : null;
    }

    /**
     * Values of the configurable attributes of the parent in the child, or null when the child lacks one.
     *
     * @return array<int, string>|null attribute id => option id
     */
    private function superAttributes(Product $parent, Product $child): ?array
    {
        $type = $parent->getTypeInstance();
        $attributes = [];

        if (!$type instanceof ConfigurableType) {
            return null;
        }

        foreach ($type->getConfigurableAttributesAsArray($parent) as $attribute) {
            $value = $child->getData((string) $attribute['attribute_code']);

            if ($value === null || $value === '') {
                return null;
            }

            $attributes[(int) $attribute['attribute_id']] = (string) $value;
        }

        return $attributes !== [] ? $attributes : null;
    }

    /** An enabled product of the website, or null. */
    public function product(int $id, int $storeId, int $websiteId): ?Product
    {
        try {
            $product = $this->products->getById($id, false, $storeId, true);
        } catch (NoSuchEntityException) {
            return null;
        }

        return $product instanceof Product && (int) $product->getStatus() === Status::STATUS_ENABLED && in_array($websiteId, array_map('intval', (array) $product->getWebsiteIds()), true)
            ? $product
            : null;
    }

    /**
     * Saves the cart with its totals counted again: the quote counts them once per request otherwise.
     */
    private function save(): void
    {
        $this->cart->getQuote()->setTotalsCollectedFlag(false);
        $this->cart->save();
    }

    /**
     * The cart as it was saved: forgets what the refused product changed in it.
     */
    private function reload(): void
    {
        $quote = $this->quotes->create()->loadActive((int) $this->cart->getQuote()->getId());

        if ($quote->getId()) {
            $this->cart->setQuote($quote);
            $this->session->replaceQuote($quote);
        }
    }
}
