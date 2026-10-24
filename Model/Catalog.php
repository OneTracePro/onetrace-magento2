<?php

declare(strict_types=1);

namespace OneTrace\Connector\Model;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\ConfigurableProduct\Model\ResourceModel\Product\Type\Configurable;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\App\Emulation;
use Magento\Store\Model\StoreManagerInterface;
use OneTrace\Commerce\CatalogItem;

/**
 * Product catalog of a store view on the platform. Only products visible in the catalog are items: a simple
 * product of a configurable one uploads its parent, so recommendations work on the product level. Other store views
 * of the same project with another locale add translations (Config::translationStores()).
 */
class Catalog
{
    /** Attributes of the description for the AI answers on the product page, the first one that has text. */
    private const DESCRIPTIONS = ['description', 'short_description'];

    /** @var array<int, array{id: string, name: string, parent_id?: string}|null> */
    private array $categories = [];

    /** @var array<int, string> category id => path ("1/2/10/14") */
    private array $categoryPaths = [];

    public function __construct(
        private readonly CollectionFactory $products,
        private readonly CategoryRepositoryInterface $categoryRepository,
        private readonly Configurable $configurable,
        private readonly StoreManagerInterface $storeManager,
        private readonly Emulation $emulation,
        private readonly Config $config,
        private readonly Stock $stock,
    ) {
    }

    /**
     * Ids of the products to upload for a changed product: itself if it is visible, and its configurable parents
     * (their stock is the sum of the children).
     *
     * @return list<int>
     */
    public function catalogIds(int $productId, int $visibility): array
    {
        $parents = array_map('intval', $this->configurable->getParentIdsByChild($productId));

        return array_values(array_unique($visibility !== Visibility::VISIBILITY_NOT_VISIBLE ? [$productId, ...$parents] : $parents));
    }

    /**
     * Products whose stock changed (orders, refunds, stock saves) with their configurable parents.
     *
     * @param array<int> $productIds
     *
     * @return list<int>
     */
    public function withParents(array $productIds): array
    {
        $productIds = array_values(array_unique(array_map('intval', $productIds)));

        if ($productIds === []) {
            return [];
        }

        return array_values(array_unique([...$productIds, ...array_map('intval', $this->configurable->getParentIdsByChild($productIds))]));
    }

    /**
     * The parent of a configurable child, for product_id in events.
     */
    public function parentId(int $productId): int
    {
        $parents = $this->configurable->getParentIdsByChild($productId);

        return $parents !== [] ? (int) reset($parents) : $productId;
    }

    /**
     * Catalog items of a store view, the categories with their ancestors, and the ids of products that are gone.
     *
     * @param list<int> $productIds
     *
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>, 2: list<string>}
     */
    public function items(int $storeId, array $productIds): array
    {
        $this->emulation->startEnvironmentEmulation($storeId, \Magento\Framework\App\Area::AREA_FRONTEND, true);

        try {
            $collection = $this->products->create()
                ->setStoreId($storeId)
                ->addStoreFilter($storeId)
                ->addIdFilter($productIds)
                // Without addPriceData(): it joins the price index and drops products that are not indexed yet.
                ->addAttributeToSelect(['name', 'status', 'visibility', 'image', 'price', 'special_price', 'special_from_date', 'special_to_date', 'url_key', 'manufacturer', 'brand', 'tax_class_id', ...self::DESCRIPTIONS])
                ->addUrlRewrite();
            $this->stock->joinSalability($collection);
            $store = $this->storeManager->getStore($storeId);
            $currency = (string) $store->getCurrentCurrencyCode();
            $mediaUrl = rtrim((string) $store->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_MEDIA), '/') . '/catalog/product';
            $items = [];
            $labels = [];
            $categoryLists = [];
            $found = [];
            foreach ($collection as $product) {
                $this->stock->salabilityLoaded($product);
            }

            $visible = array_filter($collection->getItems(), static fn (Product $product): bool => (int) $product->getVisibility() !== Visibility::VISIBILITY_NOT_VISIBLE);
            $stock = $this->stock->quantities($visible, $storeId, (int) $store->getWebsiteId());

            /** @var Product $product */
            foreach ($collection as $product) {
                $found[] = (int) $product->getId();

                if (!isset($visible[$product->getId()])) {
                    continue;
                }

                $categories = $this->categoryIds($product);
                $categoryLists[] = $categories;
                $attributes = $this->attributeValues($product);
                $labels[(string) $product->getId()] = array_map(static fn (array $attribute): string => $attribute[0], $attributes);
                $items[] = $this->item($product, $categories, $currency, $mediaUrl, $attributes, $stock[(int) $product->getId()] ?? null);
            }

            $missing = array_values(array_map('strval', array_diff($productIds, $found)));
            $categories = $this->categories(array_merge([], ...$categoryLists));
        } finally {
            $this->emulation->stopEnvironmentEmulation();
        }

        foreach ($this->config->translationStores($storeId) as $locale => $translationStoreId) {
            [$items, $categories] = $this->translate($translationStoreId, $locale, $items, $labels, $categories);
        }

        return [$items, $categories, $missing];
    }

    /**
     * Translations of items and categories from another store view of the project: the name, the link of that
     * store view, the description and the values of visible attributes (under the labels of the main store view),
     * category names.
     * Products not assigned to the website of that store view get no translation.
     *
     * @param list<array<string, mixed>> $items
     * @param array<string, array<string, string>> $labels product id => attribute code => label of the main store view
     * @param list<array<string, mixed>> $categories
     *
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>}
     */
    private function translate(int $storeId, string $locale, array $items, array $labels, array $categories): array
    {
        if ($items === []) {
            return [$items, $categories];
        }

        $this->emulation->startEnvironmentEmulation($storeId, \Magento\Framework\App\Area::AREA_FRONTEND, true);

        try {
            $collection = $this->products->create()
                ->setStoreId($storeId)
                ->addStoreFilter($storeId)
                ->addIdFilter(array_map('intval', array_column($items, 'id')))
                ->addAttributeToSelect(['name', 'url_key', ...self::DESCRIPTIONS])
                ->addUrlRewrite();
            $texts = [];

            /** @var Product $product */
            foreach ($collection as $product) {
                $texts[(string) $product->getId()] = [
                    (string) $product->getName(),
                    (string) $product->getProductUrl(),
                    array_map(static fn (array $attribute): string => $attribute[1], $this->attributeValues($product)),
                    $this->description($product),
                ];
            }

            foreach ($items as $index => $item) {
                if (!isset($texts[(string) $item['id']])) {
                    continue;
                }

                [$name, $url, $values, $description] = $texts[(string) $item['id']];
                $params = [];

                foreach ($labels[(string) $item['id']] ?? [] as $code => $label) {
                    if (isset($values[$code]) && $values[$code] !== ($item['params'][$label] ?? null)) {
                        $params[$label] = $values[$code];
                    }
                }

                $items[$index] = CatalogItem::translate(
                    $item,
                    $locale,
                    $name !== $item['name'] ? $name : null,
                    $url !== ($item['url'] ?? null) ? $url : null,
                    $params,
                    $description !== null && CatalogItem::description($description) !== ($item['description'] ?? null) ? $description : null
                );
            }

            foreach ($categories as $index => $category) {
                try {
                    $name = (string) $this->categoryRepository->get((int) $category['id'], $storeId)->getName();
                } catch (NoSuchEntityException) {
                    continue;
                }

                if ($name !== '' && $name !== $category['name']) {
                    $categories[$index] = CatalogItem::translateCategory($category, $locale, $name);
                }
            }
        } finally {
            $this->emulation->stopEnvironmentEmulation();
        }

        return [$items, $categories];
    }

    /**
     * Ids of all visible enabled products of a store view, page by page.
     *
     * @return list<int>
     */
    public function page(int $storeId, int $page, int $size): array
    {
        return array_values(array_map('intval', $this->products->create()
            ->setStoreId($storeId)
            ->addStoreFilter($storeId)
            ->addAttributeToFilter('status', Status::STATUS_ENABLED)
            ->addAttributeToFilter('visibility', ['neq' => Visibility::VISIBILITY_NOT_VISIBLE])
            ->setOrder('entity_id', 'ASC')
            ->setPageSize($size)
            ->setCurPage($page)
            ->getAllIds($size, ($page - 1) * $size)));
    }

    /**
     * Deepest category of a product (the parent's for a configurable child) — category_id of events.
     */
    public function categoryId(Product $product): ?string
    {
        $categories = $this->categoryIds($product);

        return $categories !== [] ? (string) $categories[0] : null;
    }

    /**
     * @param list<int> $categories the deepest first
     * @param array<string, array{0: string, 1: string}> $attributes attributeValues()
     * @param int|null $stock units in stock (Stock), null when the store does not track them for the product
     *
     * @return array<string, mixed>
     */
    private function item(Product $product, array $categories, string $currency, string $mediaUrl, array $attributes, ?int $stock): array
    {
        $final = (float) $product->getPriceInfo()->getPrice('final_price')->getAmount()->getValue();
        $regular = (float) $product->getPriceInfo()->getPrice('regular_price')->getAmount()->getValue();
        $image = (string) $product->getData('image');

        return CatalogItem::make(
            (int) $product->getId(),
            (string) $product->getName(),
            (string) $product->getProductUrl(),
            $image !== '' && $image !== 'no_selection' ? $mediaUrl . '/' . ltrim($image, '/') : null,
            $final > 0 ? $final : null,
            $currency,
            (int) $product->getStatus() === Status::STATUS_ENABLED && $product->isSalable(),
            $categories,
            $regular > $final ? $regular : null,
            $this->brand($product),
            array_column($attributes, 1, 0),
            $stock,
            $this->description($product)
        );
    }

    /**
     * The description of the store view, or the short one when it has no text: HTML without Magento directives
     * ({{widget}}, {{media}}, {{store}}), styles and scripts of Page Builder; the library turns it into text.
     * The template filter is not used: it renders widgets (product lists, blocks) that are not about the product.
     */
    private function description(Product $product): ?string
    {
        foreach (self::DESCRIPTIONS as $code) {
            $html = $product->getData($code);

            if (!is_string($html) || trim($html) === '') {
                continue;
            }

            $html = preg_replace(['~<(style|script|template)\b[^>]*>.*?</\1\s*>~is', '~<!--.*?-->~s', '~\{\{.*?\}\}~s'], ' ', $html) ?? '';

            if (CatalogItem::description($html) !== null) {
                return $html;
            }
        }

        return null;
    }

    /**
     * @return list<int> the deepest first
     */
    private function categoryIds(Product $product): array
    {
        $ids = array_map('intval', (array) $product->getCategoryIds());
        $levels = [];

        foreach ($ids as $id) {
            $category = $this->category($id);
            $levels[$id] = $category === null ? -1 : count(explode('/', (string) $this->categoryPaths[$id]));
        }

        $ids = array_values(array_filter($ids, static fn (int $id): bool => $levels[$id] >= 0));
        usort($ids, static fn (int $a, int $b): int => $levels[$b] <=> $levels[$a]);

        return $ids;
    }

    /**
     * Category with the root and the store root skipped (levels 0 and 1 of Magento).
     *
     * @return array{id: string, name: string, parent_id?: string}|null
     */
    private function category(int $id): ?array
    {
        if (!array_key_exists($id, $this->categories)) {
            try {
                $category = $this->categoryRepository->get($id);
                $this->categoryPaths[$id] = (string) $category->getPath();
                $this->categories[$id] = (int) $category->getLevel() >= 2 && $category->getIsActive()
                    ? CatalogItem::category($id, (string) $category->getName(), (int) $category->getLevel() > 2 ? (int) $category->getParentId() : null)
                    : null;
            } catch (NoSuchEntityException) {
                $this->categories[$id] = null;
            }
        }

        return $this->categories[$id];
    }

    /**
     * @param array<int> $ids
     *
     * @return list<array{id: string, name: string, parent_id?: string}>
     */
    private function categories(array $ids): array
    {
        $all = [];

        foreach (array_unique($ids) as $id) {
            foreach (array_reverse(explode('/', $this->categoryPaths[$id] ?? (string) $id)) as $ancestor) {
                $category = $this->category((int) $ancestor);

                if ($category !== null) {
                    $all[$category['id']] = $category;
                }
            }
        }

        return array_values($all);
    }

    private function brand(Product $product): ?string
    {
        foreach (['brand', 'manufacturer'] as $code) {
            if (!$product->getResource()->getAttribute($code)) {
                continue;
            }

            $value = $product->getAttributeText($code);

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * Attributes visible on the product page: "color" => ["Color", "White"] (the label and the value of the store view).
     *
     * @return array<string, array{0: string, 1: string}>
     */
    private function attributeValues(Product $product): array
    {
        $params = [];

        foreach ($product->getAttributes() as $attribute) {
            if (!$attribute->getIsVisibleOnFront() || in_array($attribute->getAttributeCode(), ['brand', 'manufacturer'], true)) {
                continue;
            }

            $value = $attribute->getFrontend()->getValue($product);

            if (is_scalar($value) && (string) $value !== '' && strcasecmp((string) $value, 'No') !== 0) {
                $params[(string) $attribute->getAttributeCode()] = [(string) $attribute->getStoreLabel(), strip_tags((string) $value)];
            }
        }

        return $params;
    }
}
