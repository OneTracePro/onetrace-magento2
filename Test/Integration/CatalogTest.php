<?php

declare(strict_types=1);

namespace OneTrace\Connector\Test\Integration;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Api\Data\CategoryInterfaceFactory;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product\Visibility;
use OneTrace\Connector\Test\TestCase;

final class CatalogTest extends TestCase
{
    public function testUploadsAProductWithItsCategoryTreeAndDeletesIt(): void
    {
        $categories = $this->om->get(CategoryRepositoryInterface::class);
        $suffix = bin2hex(random_bytes(3));
        $parent = $this->admin(fn () => $categories->save($this->om->get(CategoryInterfaceFactory::class)->create()->setName('Shoes')->setUrlKey('shoes-' . $suffix)->setParentId(2)->setIsActive(true)));
        $child = $this->admin(fn () => $categories->save($this->om->get(CategoryInterfaceFactory::class)->create()->setName('Sneakers')->setUrlKey('sneakers-' . $suffix)->setParentId((int) $parent->getId())->setIsActive(true)));
        $product = $this->product(['category_ids' => [(int) $parent->getId(), (int) $child->getId()]]);
        $this->flush();

        $upload = array_values(array_filter($this->platform->requests, static fn (array $r): bool => $r['method'] === 'POST' && $r['path'] === '/api/v1/products'));
        $body = end($upload)['body'];
        $item = $body['items'][0];

        self::assertSame((string) $product->getId(), $item['id']);
        self::assertSame([(string) $child->getId(), (string) $parent->getId()], $item['category_ids'], 'The deepest category first.');
        self::assertSame(49.95, $item['price']);
        self::assertTrue($item['available']);
        self::assertContains(['id' => (string) $child->getId(), 'name' => 'Sneakers', 'parent_id' => (string) $parent->getId()], $body['categories']);
        self::assertContains(['id' => (string) $parent->getId(), 'name' => 'Shoes'], $body['categories'], 'Root categories of the store are not uploaded.');

        $registry = $this->om->get(\Magento\Framework\Registry::class);
        $registry->unregister('isSecureArea');
        $registry->register('isSecureArea', true);
        $this->admin(fn () => $this->om->get(ProductRepositoryInterface::class)->delete($product));
        $this->flush();

        $last = end($this->platform->requests);
        self::assertSame(['DELETE', ['ids' => [(string) $product->getId()]]], [$last['method'], $last['body']]);

        $this->admin(fn () => $categories->delete($child) && $categories->delete($parent));
    }

    public function testDoesNotUploadProductsHiddenFromTheCatalog(): void
    {
        $this->product(['visibility' => Visibility::VISIBILITY_NOT_VISIBLE]);
        $this->flush();

        self::assertSame([], array_filter($this->platform->requests, static fn (array $r): bool => $r['path'] === '/api/v1/products' && $r['method'] === 'POST'));
    }
}
