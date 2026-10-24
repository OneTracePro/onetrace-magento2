<?php

declare(strict_types=1);

namespace OneTrace\Connector\Test;

use JsonSchema\Validator;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Type;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Config\Model\ResourceModel\Config as ConfigWriter;
use Magento\Framework\App\Config\ReinitableConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\ObjectManagerInterface;
use Magento\Quote\Api\CartManagementInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Sales\Model\Order;
use OneTrace\Connector\Model\Connection;
use OneTrace\Connector\Model\Queue;
use OneTrace\Connector\Test\Support\RecordingTransport;

abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    protected ObjectManagerInterface $om;

    protected RecordingTransport $platform;

    /** @var array<string, string|int> */
    protected const SETTINGS = [
        'onetrace/connection/url' => 'https://cdp.example.com',
        'onetrace/connection/write_key' => 'cdp_wk_test',
        'system/smtp/disable' => 1,
        'newsletter/subscription/confirm' => 0,
        'newsletter/subscription/allow_guest_subscribe' => 1,
    ];

    protected function setUp(): void
    {
        $this->om = $GLOBALS['onetraceObjectManager'];
        $writer = $this->om->get(ConfigWriter::class);

        foreach (static::SETTINGS + ['onetrace/connection/secret_key' => $this->om->get(EncryptorInterface::class)->encrypt('cdp_sk_test')] as $path => $value) {
            $writer->saveConfig($path, (string) $value);
        }

        $this->om->get(ReinitableConfigInterface::class)->reinit();
        $this->om->get(\Magento\Framework\App\Cache\TypeListInterface::class)->cleanType('config');

        $db = $this->om->get(ResourceConnection::class)->getConnection();
        $db->delete($db->getTableName('onetrace_queue'));
        $this->om->get(Queue::class)->resume();

        $this->platform = new RecordingTransport();
        $this->om->get(Connection::class)->reset(['transport' => $this->platform]);
        $_COOKIE = [];
    }

    protected function product(array $data = []): ProductInterface
    {
        $product = $this->om->create(ProductInterface::class);
        $sku = 'onetrace-' . bin2hex(random_bytes(4));
        $product->setTypeId(Type::TYPE_SIMPLE)
            ->setAttributeSetId(4)
            ->setSku($sku)
            ->setUrlKey($sku)
            ->setName($data['name'] ?? 'Sneakers')
            ->setPrice($data['price'] ?? 49.95)
            ->setVisibility($data['visibility'] ?? Visibility::VISIBILITY_BOTH)
            ->setStatus(Status::STATUS_ENABLED)
            ->setWebsiteIds([1])
            ->setStockData($data['stock'] ?? ['use_config_manage_stock' => 1, 'qty' => 100, 'is_in_stock' => 1]);

        if (isset($data['category_ids'])) {
            $product->setCategoryIds($data['category_ids']);
        }

        foreach (['description', 'short_description'] as $code) {
            if (isset($data[$code])) {
                $product->setData($code, $data[$code]);
            }
        }

        return $this->admin(fn () => $this->om->get(ProductRepositoryInterface::class)->save($product));
    }

    /**
     * A store view of the main website with its own settings (store scope), e.g. another language of the store.
     *
     * @param array<string, string> $settings
     */
    protected function storeView(string $code, array $settings = []): int
    {
        $store = $this->om->create(\Magento\Store\Model\Store::class);
        $store->setCode($code)->setName($code)->setWebsiteId(1)->setGroupId(1)->setSortOrder(100)->setIsActive(1);
        $store->getResource()->save($store);
        $writer = $this->om->get(ConfigWriter::class);

        foreach ($settings as $path => $value) {
            $writer->saveConfig($path, $value, \Magento\Store\Model\ScopeInterface::SCOPE_STORES, (int) $store->getId());
        }

        $this->reinit();

        return (int) $store->getId();
    }

    protected function deleteStoreView(int $storeId): void
    {
        $registry = $this->om->get(\Magento\Framework\Registry::class);
        $registry->unregister('isSecureArea');
        $registry->register('isSecureArea', true);
        $store = $this->om->create(\Magento\Store\Model\Store::class)->load($storeId);
        $store->getResource()->delete($store);
        $writer = $this->om->get(ConfigWriter::class);
        $writer->getConnection()->delete($writer->getMainTable(), ['scope = ?' => 'stores', 'scope_id = ?' => $storeId]);
        $this->reinit();
    }

    protected function reinit(): void
    {
        $this->om->get(ReinitableConfigInterface::class)->reinit();
        $this->om->get(\Magento\Framework\App\Cache\TypeListInterface::class)->cleanType('config');
        $this->om->get(\Magento\Store\Model\StoreManagerInterface::class)->reinitStores();
    }

    /**
     * Catalog changes are made in the admin, as in a real store (Magento 2.4.7 builds category URL rewrites of a
     * product saved elsewhere with errors).
     */
    protected function admin(callable $callback): mixed
    {
        return $this->om->get(\Magento\Framework\App\State::class)->emulateAreaCode(\Magento\Framework\App\Area::AREA_ADMINHTML, $callback);
    }

    /**
     * A guest order through the real checkout service (quote → submit), check/money order and flat rate.
     */
    protected function order(ProductInterface $product, int $quantity = 2, string $email = 'anna@example.com'): Order
    {
        $management = $this->om->get(CartManagementInterface::class);
        $quotes = $this->om->get(CartRepositoryInterface::class);
        $quote = $quotes->get($management->createEmptyCart());
        $quote->setStoreId(1)->setCustomerEmail($email)->setCustomerIsGuest(true)->setCheckoutMethod('guest');
        $quote->addProduct($this->om->get(ProductRepositoryInterface::class)->get($product->getSku(), false, 1, true), $quantity);

        $address = ['firstname' => 'Anna', 'lastname' => 'Schmidt', 'street' => 'Torstraße 1', 'city' => 'Berlin', 'country_id' => 'DE', 'postcode' => '10119', 'telephone' => '+49 151 1234-5678'];
        $quote->getBillingAddress()->addData($address);
        $quote->getShippingAddress()->addData($address)->setCollectShippingRates(true)->collectShippingRates()->setShippingMethod('flatrate_flatrate');
        $quote->getPayment()->importData(['method' => 'checkmo']);
        $quote->collectTotals();
        $quotes->save($quote);

        /** @var Order */
        return $management->submit($quotes->get($quote->getId()));
    }

    /**
     * The last catalog upload of a product.
     *
     * @return array<string, mixed>|null
     */
    protected function uploaded(int $productId): ?array
    {
        $found = null;

        foreach ($this->platform->requests as $request) {
            if ($request['method'] === 'POST' && $request['path'] === '/api/v1/products') {
                foreach ($request['body']['items'] ?? [] as $item) {
                    if ($item['id'] === (string) $productId) {
                        $found = $item;
                    }
                }
            }
        }

        return $found;
    }

    protected function flush(): array
    {
        return $this->om->get(Queue::class)->flush(PHP_INT_MAX);
    }

    /**
     * @param array<string, mixed> $message
     */
    protected static function assertContract(array $message): void
    {
        $validator = new Validator();
        $data = json_decode((string) json_encode($message));
        $validator->validate($data, (object) ['$ref' => 'file:///data/vendor/onetracepro/onetrace-php/resources/ecommerce-events.schema.json']);

        self::assertTrue($validator->isValid(), (string) json_encode(['message' => $message, 'errors' => $validator->getErrors()], JSON_PRETTY_PRINT));
    }
}
