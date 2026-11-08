<?php

declare(strict_types=1);

namespace OneTrace\Connector\Test\Integration;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Customer\Api\AccountManagementInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory as OrderCollectionFactory;
use Magento\Store\Model\StoreManagerInterface;
use OneTrace\Connector\Model\StoreMcp;
use OneTrace\Connector\Model\StoreMcp\PaymentLinks;
use OneTrace\Connector\Test\TestCase;
use OneTrace\Http\Request;
use OneTrace\Http\Response;

/**
 * Orders from AI shopping assistants through the store MCP (contract v2): the quote with delivery and payment
 * methods, a guest order after a code to a new email, an order of an account, the same order for a repeat with the
 * same idempotency key (also after the cache is flushed), prices checked against the quote, the assistant in the
 * order comment and in order_completed, the signed payment link.
 */
final class StoreMcpOrdersTest extends TestCase
{
    private const MAILPIT = 'http://127.0.0.1:8025/api/v1/';

    protected const SETTINGS = [
        'system/smtp/disable' => 0,
        'system/smtp/transport' => 'smtp',
        'system/smtp/host' => '127.0.0.1',
        'system/smtp/port' => 1025,
        'onetrace/store_mcp/enabled' => 1,
        'onetrace/store_mcp/tools' => 'store_start_verification,store_verify,store_get_customer,store_find_orders,store_get_order,store_check_availability,store_quote_order,store_create_order',
        'onetrace/store_mcp/payment_methods' => 'checkmo,purchaseorder',
        'carriers/flatrate/active' => 1,
        'carriers/flatrate/type' => 'I',
        'carriers/flatrate/price' => 5,
        'carriers/freeshipping/active' => 0,
        'payment/checkmo/active' => 1,
        'payment/purchaseorder/active' => 1,
        'payment/banktransfer/active' => 1,
    ] + parent::SETTINGS;

    private const SHIPPING = ['first_name' => 'Anna', 'last_name' => 'Schmidt', 'phone' => '+49 151 1234-5678', 'country' => 'DE', 'city' => 'Berlin', 'postcode' => '10119', 'address' => 'Torstraße 1'];

    private StoreMcp $storeMcp;

    /** Idempotency keys of the platform are new every run: results are kept for a day. */
    private string $run;

    protected function setUp(): void
    {
        parent::setUp();
        $this->run = bin2hex(random_bytes(4));
        $this->om->get(StoreManagerInterface::class)->setCurrentStore(1);
        $this->storeMcp = $this->om->get(StoreMcp::class);
        $this->platform->responder = static fn (Request $request): ?Response => str_ends_with((string) parse_url($request->getUrl(), PHP_URL_PATH), '/emails/settings')
            ? new Response(403, ['content-type' => 'application/json'], '{"message":"No"}')
            : null;
        $this->mailpit('DELETE', 'messages');
    }

    protected function tearDown(): void
    {
        $writer = $this->om->get(\Magento\Config\Model\ResourceModel\Config::class);

        foreach (['system/smtp/disable' => '1', 'payment/purchaseorder/active' => '0', 'payment/banktransfer/active' => '0'] as $path => $value) {
            $writer->saveConfig($path, $value);
        }

        $this->om->get(StoreManagerInterface::class)->setCurrentStore('admin');
        $this->reinit();
        parent::tearDown();
    }

    public function testAnnouncesContractVersion2WithTheOrderTools(): void
    {
        $init = $this->rpc('initialize')['result'];
        $tools = array_column($this->rpc('tools/list')['result']['tools'], 'name');

        self::assertSame(['version' => 2], $init['capabilities']['experimental']['onetrace/store']);
        self::assertContains('store_quote_order', $tools);
        self::assertContains('store_create_order', $tools);
    }

    public function testQuotesProductsWithDeliveryAndPaymentMethodsForTheAddress(): void
    {
        $shoes = $this->product(['price' => 40]);
        $sold = $this->product(['stock' => ['use_config_manage_stock' => 1, 'qty' => 0, 'is_in_stock' => 0]]);
        $few = $this->product(['stock' => ['use_config_manage_stock' => 1, 'qty' => 1, 'is_in_stock' => 1]]);
        $currency = (string) $this->om->get(StoreManagerInterface::class)->getStore(1)->getCurrentCurrencyCode();

        $quote = $this->call('store_quote_order', [
            'items' => [['product_id' => (string) $shoes->getId(), 'quantity' => 2], ['product_id' => (string) $sold->getId(), 'quantity' => 1], ['product_id' => (string) $few->getId(), 'quantity' => 3], ['product_id' => '999999999', 'quantity' => 1], ['product_id' => 'sku-x', 'quantity' => 1]],
            'shipping' => ['country' => 'DE', 'city' => 'Berlin', 'postcode' => '10119'],
        ]);

        self::assertEquals([['product_id' => (string) $shoes->getId(), 'name' => 'Sneakers', 'quantity' => 2, 'price' => ['amount' => 40.0, 'currency' => $currency], 'total' => ['amount' => 80.0, 'currency' => $currency]]], $quote['items']);
        self::assertEqualsCanonicalizing([(string) $sold->getId(), (string) $few->getId(), '999999999', 'sku-x'], $quote['unavailable']);
        self::assertEquals(['amount' => 80.0, 'currency' => $currency], $quote['subtotal']);
        self::assertSame('flatrate_flatrate', $quote['shipping_method']);
        self::assertContains('flatrate_flatrate', array_column($quote['shipping_methods'], 'id'));
        // Flat rate per item: 2 × 5. Amounts are JSON numbers: whole ones come back as integers.
        self::assertEquals(['amount' => 10.0, 'currency' => $currency], $quote['shipping_price']);
        self::assertEquals(['amount' => 90.0, 'currency' => $currency], $quote['total']);
        // Purchase order needs a number from the customer, bank transfer is not allowed by the owner.
        self::assertSame(['checkmo'], array_column($quote['payment_methods'], 'id'));
        self::assertSame([], $this->orders($shoes), 'A quote places nothing.');
    }

    public function testAGuestConfirmsANewEmailAndPlacesAnOrderOnceForTheIdempotencyKey(): void
    {
        $email = 'ai-guest-' . bin2hex(random_bytes(4)) . '@example.com';
        $product = $this->product(['price' => 30]);
        $items = [['product_id' => (string) $product->getId(), 'quantity' => 1]];

        self::assertTrue($this->call('store_start_verification', ['contact' => $email, 'purpose' => 'order'])['sent']);
        $mail = $this->mailpit('GET', 'messages')['messages'][0];
        self::assertSame(1, preg_match('/\b(\d{6})\b/', (string) $this->mailpit('GET', 'message/' . $mail['ID'])['Text'], $code));
        $token = $this->call('store_verify', ['contact' => $email, 'code' => $code[1]])['customer_token'];

        self::assertSame(['id' => null, 'email' => $email], $this->call('store_get_customer', [], $token));
        self::assertSame('auth_required', $this->call('store_find_orders', [], $token)['error']);

        $quote = $this->call('store_quote_order', ['items' => $items, 'shipping' => self::SHIPPING]);
        $args = ['items' => $items, 'shipping' => self::SHIPPING, 'shipping_method' => $quote['shipping_method'], 'payment_method' => 'checkmo', 'comment' => 'Please ring twice'];

        self::assertSame('not_allowed', $this->call('store_create_order', $args, $token, false, 'order-1')['error'], 'Without the consent.');

        $created = $this->call('store_create_order', $args, $token, true, 'order-1');
        $again = $this->call('store_create_order', $args, $token, true, 'order-1');
        // The cache of the server is gone: the order is found by its key in the database.
        $this->om->get(CacheInterface::class)->clean(['ONETRACE_STORE_MCP']);
        $afterFlush = $this->call('store_create_order', $args, $token, true, 'order-1');
        $orders = $this->orders($product);

        self::assertCount(1, $orders);
        $order = $orders[0];
        self::assertSame($created, $again);
        self::assertSame($created, $afterFlush);
        self::assertSame(['order', 'status', 'total', 'payment_status', 'payment_url'], array_keys($created));
        self::assertSame($order->getIncrementId(), $created['order']);
        self::assertSame('pending', $created['status']);
        self::assertSame('pending', $created['payment_status']);
        self::assertSame($quote['total'], $created['total']);
        self::assertTrue((bool) $order->getCustomerIsGuest());
        self::assertSame($email, $order->getCustomerEmail());
        self::assertSame('checkmo', $order->getPayment()->getMethod());
        self::assertSame('flatrate_flatrate', $order->getShippingMethod());
        self::assertSame(['Torstraße 1'], $order->getShippingAddress()->getStreet());
        self::assertSame('10119', $order->getShippingAddress()->getPostcode());
        $comments = array_map(static fn ($history): string => (string) $history->getComment(), $order->getStatusHistories());
        self::assertContains('Placed through OneTrace by the AI assistant of the customer: Claude.', $comments);
        self::assertContains('Comment of the customer: Please ring twice', $comments);

        $this->flush();
        $completed = array_values(array_filter($this->platform->events('order_completed'), static fn (array $event): bool => $event['properties']['order_id'] === $order->getIncrementId()));
        self::assertCount(1, $completed);
        self::assertSame('claude', $completed[0]['properties']['ai_assistant']);
        self::assertContract($completed[0]);

        // Another key is another order.
        self::assertNotSame($created['order'], $this->call('store_create_order', $args, $token, true, 'order-2')['order']);
    }

    public function testAnAccountOrdersWithItsOwnTokenAndPaysByTheSignedLink(): void
    {
        $customer = $this->customer();
        $token = (string) $this->storeMcp->customerToken(1, (int) $customer->getId());
        $product = $this->product();
        $items = [['product_id' => (string) $product->getId(), 'quantity' => 2]];

        $created = $this->call('store_create_order', ['items' => $items, 'shipping' => self::SHIPPING, 'shipping_method' => 'flatrate_flatrate'], $token, true, 'account-1', 'chatgpt');
        [$order] = $this->orders($product);

        self::assertSame((int) $customer->getId(), (int) $order->getCustomerId());
        self::assertFalse((bool) $order->getCustomerIsGuest());
        self::assertSame('checkmo', $order->getPayment()->getMethod(), 'The first allowed method without payment_method.');

        parse_str((string) parse_url($created['payment_url'], PHP_URL_QUERY), $query);
        $links = $this->om->get(PaymentLinks::class);
        self::assertStringContainsString('/onetrace/order/pay', $created['payment_url']);
        self::assertSame((int) $order->getId(), $links->verify($query['order'], $query['expires'], $query['signature']));
        self::assertNull($links->verify((string) ((int) $query['order'] + 1), $query['expires'], $query['signature']));
        self::assertNull($links->verify($query['order'], (string) ((int) $query['expires'] + 1), $query['signature']));
    }

    public function testTakesTheStateOfACountryThatNeedsOneFromTheAddress(): void
    {
        $token = $this->storeMcp->tokens()->issueGuest('ai-us-' . bin2hex(random_bytes(4)) . '@example.com');
        $product = $this->product();
        $us = ['country' => 'US', 'city' => 'Austin', 'postcode' => '78701', 'address' => '100 Congress Ave, TX'] + self::SHIPPING;
        $args = ['items' => [['product_id' => (string) $product->getId(), 'quantity' => 1]], 'shipping' => $us, 'shipping_method' => 'flatrate_flatrate'];

        $missing = $this->call('store_create_order', ['shipping' => ['address' => '100 Congress Ave'] + $us] + $args, $token, true, 'no-state');
        self::assertSame('invalid_input', $missing['error']);
        self::assertStringContainsString('state or region', $missing['message']);

        $this->call('store_create_order', $args, $token, true, 'state');
        [$order] = $this->orders($product);
        self::assertSame('Texas', $order->getShippingAddress()->getRegion());
        self::assertSame('Texas', $order->getBillingAddress()->getRegion());
    }

    public function testRefusesOrdersWithChangedPricesUnavailableProductsAndUnknownMethods(): void
    {
        $token = $this->storeMcp->tokens()->issueGuest('ai-' . bin2hex(random_bytes(4)) . '@example.com');
        $product = $this->product(['price' => 20]);
        $items = [['product_id' => (string) $product->getId(), 'quantity' => 1]];
        $args = ['items' => $items, 'shipping' => self::SHIPPING, 'shipping_method' => 'flatrate_flatrate'];
        $this->call('store_quote_order', $args);

        $product = $this->om->get(ProductRepositoryInterface::class)->get((string) $product->getSku(), true, 0, true);
        $this->admin(fn () => $this->om->get(ProductRepositoryInterface::class)->save($product->setPrice(25)));

        $changed = $this->call('store_create_order', $args, $token, true, 'price');
        self::assertSame('invalid_input', $changed['error']);
        self::assertStringContainsString('Prices have changed', $changed['message']);

        self::assertSame('invalid_input', $this->call('store_create_order', ['shipping_method' => 'tablerate_bestway'] + $args, $token, true, 'method')['error']);
        self::assertSame('invalid_input', $this->call('store_create_order', ['payment_method' => 'purchaseorder'] + $args, $token, true, 'payment')['error']);
        self::assertSame('invalid_input', $this->call('store_create_order', ['items' => [['product_id' => '999999999', 'quantity' => 1]]] + $args, $token, true, 'unknown')['error']);
        self::assertSame([], $this->orders($product));

        // Quoted again: the new price is the agreed one; the refused keys are free again.
        $this->call('store_quote_order', $args);
        self::assertEquals(['amount' => 30.0, 'currency' => $this->call('store_quote_order', $args)['total']['currency']], $this->call('store_create_order', $args, $token, true, 'price')['total']);
    }

    /**
     * Orders of a product, newest first.
     *
     * @return list<Order>
     */
    private function orders(\Magento\Catalog\Api\Data\ProductInterface $product): array
    {
        $collection = $this->om->create(OrderCollectionFactory::class)->create()->setOrder('entity_id', 'DESC');
        $collection->getSelect()->where('main_table.entity_id IN (?)', $this->om->get(ResourceConnection::class)->getConnection()->select()->from($collection->getTable('sales_order_item'), ['order_id'])->where('product_id = ?', (int) $product->getId()));

        return array_values($collection->getItems());
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function rpc(string $method, array $params = []): array
    {
        $response = $this->storeMcp->server(1)->handle('POST', ['Authorization' => 'Bearer ' . $this->storeMcp->key()], (string) json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params]));

        return (array) json_decode($response['body'], true);
    }

    /**
     * structuredContent of a tool call from an AI assistant.
     *
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function call(string $tool, array $arguments = [], ?string $token = null, bool $confirmed = false, ?string $key = null, string $assistant = 'claude'): array
    {
        $meta = array_filter([
            'onetrace/customer_token' => $token,
            'onetrace/confirmed' => $confirmed ?: null,
            'onetrace/idempotency_key' => $key !== null ? $key . '-' . $this->run : null,
            'onetrace/source' => 'assistant',
            'onetrace/assistant' => $assistant,
        ]);

        return $this->rpc('tools/call', ['name' => $tool, 'arguments' => $arguments, '_meta' => $meta])['result']['structuredContent'];
    }

    private function customer(): CustomerInterface
    {
        $customer = $this->om->create(CustomerInterface::class)
            ->setEmail('ai-account-' . bin2hex(random_bytes(4)) . '@example.com')
            ->setFirstname('Anna')
            ->setLastname('Schmidt')
            ->setWebsiteId(1)
            ->setStoreId(1);

        return $this->om->get(AccountManagementInterface::class)->createAccount($customer);
    }

    /**
     * @return array<string, mixed>
     */
    private function mailpit(string $method, string $path): array
    {
        $context = stream_context_create(['http' => ['method' => $method, 'ignore_errors' => true, 'timeout' => 5]]);
        $body = @file_get_contents(self::MAILPIT . $path, false, $context);

        if ($method !== 'GET') {
            return [];
        }

        self::assertIsString($body, 'Mailpit is running (tests/env/run.sh starts it).');

        return (array) json_decode($body, true);
    }
}
