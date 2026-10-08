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
 * product of a configurable one uploads its parent, so recommendations work on the product level.
 */
class Catalog
{
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
    ) {
    }

    /**
     * Ids of the products to upload for a changed product: itself if it is visible, otherwise its parents.
     *
     * @return list<int>
     */
    public function catalogIds(int $productId, int $visibility): array
    {
        if ($visibility !== Visibility::VISIBILITY_NOT_VISIBLE) {
            return [$productId];
        }

        return array_values(array_map('intval', $this->configurable->getParentIdsByChild($productId)));
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
     * @return array{0: list<array<string, mixed>>, 1: list<array{id: string, name: string, parent_id?: string}>, 2: list<string>}
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
                ->addAttributeToSelect(['name', 'status', 'visibility', 'image', 'price', 'special_price', 'special_from_date', 'special_to_date', 'url_key', 'manufacturer', 'brand', 'tax_class_id'])
                ->addUrlRewrite();
            $store = $this->storeManager->getStore($storeId);
            $currency = (string) $store->getCurrentCurrencyCode();
            $mediaUrl = rtrim((string) $store->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_MEDIA), '/') . '/catalog/product';
            $items = [];
            $categoryLists = [];
            $found = [];

            /** @var Product $product */
            foreach ($collection as $product) {
                $found[] = (int) $product->getId();

                if ((int) $product->getVisibility() === Visibility::VISIBILITY_NOT_VISIBLE) {
                    continue;
                }

                $categories = $this->categoryIds($product);
                $categoryLists[] = $categories;
                $items[] = $this->item($product, $categories, $currency, $mediaUrl);
            }

            $missing = array_values(array_map('strval', array_diff($productIds, $found)));

            return [$items, $this->categories(array_merge([], ...$categoryLists)), $missing];
        } finally {
            $this->emulation->stopEnvironmentEmulation();
        }
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
     *
     * @return array<string, mixed>
     */
    private function item(Product $product, array $categories, string $currency, string $mediaUrl): array
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
            $this->attributes($product)
        );
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
     * Attributes visible on the product page: "Color" => "White".
     *
     * @return array<string, string>
     */
    private function attributes(Product $product): array
    {
        $params = [];

        foreach ($product->getAttributes() as $attribute) {
            if (!$attribute->getIsVisibleOnFront() || in_array($attribute->getAttributeCode(), ['brand', 'manufacturer'], true)) {
                continue;
            }

            $value = $attribute->getFrontend()->getValue($product);

            if (is_scalar($value) && (string) $value !== '' && strcasecmp((string) $value, 'No') !== 0) {
                $params[(string) $attribute->getStoreLabel()] = strip_tags((string) $value);
            }
        }

        return $params;
    }
}
