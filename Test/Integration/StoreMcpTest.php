<?php

declare(strict_types=1);

namespace OneTrace\Connector\Test\Integration;

use Magento\Customer\Api\AccountManagementInterface;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\DB\Transaction;
use Magento\Sales\Api\Data\ShipmentTrackCreationInterfaceFactory;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Api\ShipOrderInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\Service\InvoiceService;
use Magento\Store\Model\StoreManagerInterface;
use OneTrace\Connector\Block\Adminhtml\StoreMcpStatus;
use OneTrace\Connector\Model\StoreMcp;
use OneTrace\Connector\Test\TestCase;
use OneTrace\Http\Request;
use OneTrace\Http\Response;

/**
 * MCP server of the store for the OneTrace AI chat bot: the platform key, the allowed tools, confirmation by a code
 * the store emails, orders of the confirmed customer only, the cancellation of unpaid orders only, requests to the
 * staff once per idempotency key and the connection to the bot of the project.
 */
final class StoreMcpTest extends TestCase
{
    private const MAILPIT = 'http://127.0.0.1:8025/api/v1/';

    protected const SETTINGS = [
        'system/smtp/disable' => 0,
        'system/smtp/transport' => 'smtp',
        'system/smtp/host' => '127.0.0.1',
        'system/smtp/port' => 1025,
        'onetrace/store_mcp/enabled' => 1,
        'onetrace/store_mcp/tools' => 'store_start_verification,store_verify,store_get_customer,store_find_orders,store_get_order,store_get_tracking,store_check_availability,store_cancel_order,store_change_delivery',
    ] + parent::SETTINGS;

    private StoreMcp $storeMcp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->om->get(StoreManagerInterface::class)->setCurrentStore(1);
        $this->storeMcp = $this->om->get(StoreMcp::class);
        // Store emails of the platform are off: Magento sends the code itself, to Mailpit.
        $this->platform->responder = static fn (Request $request): ?Response => str_ends_with((string) parse_url($request->getUrl(), PHP_URL_PATH), '/emails/settings')
            ? new Response(403, ['content-type' => 'application/json'], '{"message":"No"}')
            : null;
        $this->mailpit('DELETE', 'messages');
    }

    protected function tearDown(): void
    {
        $this->om->get(\Magento\Config\Model\ResourceModel\Config::class)->saveConfig('system/smtp/disable', '1');
        $this->om->get(StoreManagerInterface::class)->setCurrentStore('admin');
        $this->reinit();
        parent::tearDown();
    }

    public function testAnswersTheContractWithTheAllowedToolsForThePlatformKeyOnly(): void
    {
        $init = $this->rpc('initialize')['data']['result'];
        $tools = array_column($this->rpc('tools/list')['data']['result']['tools'], 'name');

        self::assertSame(['version' => 1], $init['capabilities']['experimental']['onetrace/store']);
        self::assertContains('store_cancel_order', $tools);
        self::assertNotContains('store_create_return', $tools);
        self::assertSame(401, $this->rpc('tools/list', [], 'wrong-key')['status']);
    }

    public function testConfirmsAGuestByACodeByEmailAndShowsOnlyTheirOrders(): void
    {
        // A new address every run: the code limit (5 an hour) lives in the Magento cache of the container.
        $guest = 'guest-' . bin2hex(random_bytes(4)) . '@example.com';
        $product = $this->product();
        $mine = $this->order($product, 1, $guest);
        $other = $this->order($product, 1, 'other-' . bin2hex(random_bytes(4)) . '@example.com');

        self::assertSame('auth_required', $this->call('store_get_order', ['order' => $mine->getIncrementId()])['error']);

        self::assertTrue($this->call('store_start_verification', ['contact' => $guest])['sent']);
        $mail = $this->mailpit('GET', 'messages')['messages'][0];
        self::assertSame($guest, strtolower((string) $mail['To'][0]['Address']));
        self::assertSame(1, preg_match('/\b(\d{6})\b/', (string) $this->mailpit('GET', 'message/' . $mail['ID'])['Text'], $code));

        self::assertSame('invalid_code', $this->call('store_verify', ['contact' => $guest, 'code' => $code[1] === '000000' ? '111111' : '000000'])['error']);
        $token = $this->call('store_verify', ['contact' => $guest, 'code' => $code[1]])['customer_token'];
        $orders = $this->call('store_find_orders', [], $token)['orders'];

        self::assertSame([$mine->getIncrementId()], array_column($orders, 'order'));
        self::assertSame('pending', $orders[0]['status']);
        self::assertSame('not_found', $this->call('store_get_order', ['order' => $other->getIncrementId()], $token)['error']);
        self::assertSame(['amount' => round((float) $mine->getGrandTotal(), 2), 'currency' => (string) $mine->getOrderCurrencyCode()], $this->call('store_get_order', ['order' => $mine->getIncrementId()], $token)['total']);
    }

    public function testAnAccountSeesItsOrdersAndItsGuestOrdersWithTrackingOfShipments(): void
    {
        $customer = $this->customer();
        $token = $this->storeMcp->customerToken(1, (int) $customer->getId());
        $guestOrder = $this->order($this->product(), 1, (string) $customer->getEmail());
        $accountOrder = $this->order($this->product(), 1, (string) $customer->getEmail());
        $accountOrder->setCustomerId((int) $customer->getId())->setCustomerIsGuest(false);
        $this->om->get(OrderRepositoryInterface::class)->save($accountOrder);
        $this->invoice($accountOrder);
        $track = $this->om->get(ShipmentTrackCreationInterfaceFactory::class)->create()->setTitle('DHL')->setCarrierCode('custom')->setTrackNumber('JD0142');
        $this->om->get(ShipOrderInterface::class)->execute((int) $accountOrder->getId(), [], false, false, null, [$track]);

        self::assertNotNull($token);
        self::assertSame('customer:' . $customer->getId(), $this->storeMcp->tokens()->verify($token));
        self::assertEqualsCanonicalizing([$guestOrder->getIncrementId(), $accountOrder->getIncrementId()], array_column($this->call('store_find_orders', [], $token)['orders'], 'order'));
        self::assertSame(['carrier' => 'DHL', 'tracking_number' => 'JD0142', 'status' => 'completed'], array_intersect_key($this->call('store_get_tracking', ['order' => $accountOrder->getIncrementId()], $token), array_flip(['carrier', 'tracking_number', 'status'])));
        self::assertSame('not_found', $this->call('store_get_tracking', ['order' => $guestOrder->getIncrementId()], $token)['error']);
        self::assertSame('Anna', $this->call('store_get_customer', [], $token)['first_name']);
    }

    public function testCancelsOnlyUnpaidOrdersOfTheCustomerAfterConsent(): void
    {
        $email = 'cancel-' . bin2hex(random_bytes(4)) . '@example.com';
        $token = $this->storeMcp->tokens()->issue('email:' . $email);
        $unpaid = $this->order($this->product(), 1, $email);

        self::assertSame('not_allowed', $this->call('store_cancel_order', ['order' => $unpaid->getIncrementId()], $token)['error']);

        self::assertSame('cancelled', $this->call('store_cancel_order', ['order' => $unpaid->getIncrementId(), 'reason' => 'Changed my mind'], $token, true)['status']);
        $cancelled = $this->om->create(OrderRepositoryInterface::class)->get((int) $unpaid->getId());
        self::assertSame(Order::STATE_CANCELED, $cancelled->getState());
        self::assertNotEmpty(array_filter($cancelled->getStatusHistories(), static fn ($history): bool => str_contains((string) $history->getComment(), 'Changed my mind')));

        $paid = $this->order($this->product(), 1, $email);
        $this->invoice($paid);
        self::assertSame('not_allowed', $this->call('store_cancel_order', ['order' => $paid->getIncrementId()], $token, true)['error']);
    }

    public function testPassesADeliveryChangeToTheStaffOnceAndRefusesToolsTheOwnerDidNotAllow(): void
    {
        $email = 'delivery-' . bin2hex(random_bytes(4)) . '@example.com';
        $token = $this->storeMcp->tokens()->issue('email:' . $email);
        $order = $this->order($this->product(), 1, $email);

        $first = $this->call('store_change_delivery', ['order' => $order->getIncrementId(), 'address' => 'Berlin, Torstraße 5'], $token, true);
        $again = $this->call('store_change_delivery', ['order' => $order->getIncrementId(), 'address' => 'Berlin, Torstraße 5'], $token, true);
        $comments = array_filter(
            $this->om->create(OrderRepositoryInterface::class)->get((int) $order->getId())->getStatusHistories(),
            static fn ($history): bool => str_contains((string) $history->getComment(), 'Torstraße 5')
        );

        self::assertSame($first, $again);
        self::assertCount(1, $comments);
        $mails = $this->mailpit('GET', 'messages')['messages'];
        self::assertCount(1, $mails, 'One email to the staff.');
        self::assertStringContainsString($order->getIncrementId() . ': delivery change request', (string) $mails[0]['Subject']);
        // Returns are not allowed in the settings of the test.
        self::assertSame('not_allowed', $this->call('store_create_return', ['order' => $order->getIncrementId(), 'items' => [], 'reason' => 'Too big'], $token, true)['error']);
    }

    public function testChecksAvailabilityOfVisibleProducts(): void
    {
        $product = $this->product(['name' => 'Trail Runner ' . bin2hex(random_bytes(3))]);
        $found = $this->call('store_check_availability', ['sku' => $product->getSku()])['products'];

        self::assertSame([(string) $product->getId()], array_column($found, 'product_id'));
        self::assertTrue($found[0]['in_stock']);
        self::assertSame(['amount' => 49.95, 'currency' => (string) $this->om->get(StoreManagerInterface::class)->getStore(1)->getBaseCurrencyCode()], $found[0]['price']);
        self::assertSame([(string) $product->getId()], array_column($this->call('store_check_availability', ['query' => $product->getName()])['products'], 'product_id'));
        self::assertSame([], $this->call('store_check_availability', ['sku' => 'no-such-' . bin2hex(random_bytes(3))])['products']);
    }

    public function testConnectsTheServerToTheBotOfTheProjectAndDisconnectsItWhenTurnedOff(): void
    {
        $this->platform->responder = static fn (Request $request): ?Response => (string) parse_url($request->getUrl(), PHP_URL_PATH) === '/api/v1/chat/store-mcp' && $request->getMethod() === 'PUT'
            ? new Response(200, ['content-type' => 'application/json'], '{"status":"ok","error":null,"tools":["store_get_order"]}')
            : null;

        $this->storeMcp->connect();
        $put = $this->requests('PUT', '/api/v1/chat/store-mcp');

        self::assertCount(1, $put);
        self::assertStringContainsString('/onetrace/mcp', $put[0]['body']['url']);
        self::assertSame($this->storeMcp->key(), $put[0]['body']['key']);
        self::assertSame('magento2', $put[0]['body']['platform']);
        self::assertStringContainsString('store_get_order', $this->om->get(StoreMcpStatus::class)->line(1));

        $this->om->get(\Magento\Config\Model\ResourceModel\Config::class)->saveConfig('onetrace/store_mcp/enabled', '0');
        $this->reinit();
        $this->storeMcp->connect();

        self::assertSame([['url' => $put[0]['body']['url']]], array_column($this->requests('DELETE', '/api/v1/chat/store-mcp'), 'body'));
        self::assertNull($this->storeMcp->status(1));
        self::assertNull($this->storeMcp->customerToken(1, 5));
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array{status: int, data: mixed}
     */
    private function rpc(string $method, array $params = [], ?string $key = null): array
    {
        $response = $this->storeMcp->server(1)->handle('POST', ['Authorization' => 'Bearer ' . ($key ?? $this->storeMcp->key())], (string) json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params]));

        return ['status' => $response['status'], 'data' => json_decode($response['body'], true)];
    }

    /**
     * structuredContent of a tool call.
     *
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function call(string $tool, array $arguments = [], ?string $token = null, bool $confirmed = false): array
    {
        $meta = array_filter(['onetrace/customer_token' => $token, 'onetrace/confirmed' => $confirmed ?: null, 'onetrace/idempotency_key' => $confirmed ? 'k-' . $tool . '-' . json_encode($arguments) : null]);

        return $this->rpc('tools/call', ['name' => $tool, 'arguments' => $arguments, '_meta' => $meta])['data']['result']['structuredContent'];
    }

    private function customer(): CustomerInterface
    {
        $customer = $this->om->create(CustomerInterface::class)
            ->setEmail('account-' . bin2hex(random_bytes(4)) . '@example.com')
            ->setFirstname('Anna')
            ->setLastname('Schmidt')
            ->setWebsiteId(1)
            ->setStoreId(1);

        return $this->om->get(AccountManagementInterface::class)->createAccount($customer);
    }

    private function invoice(Order $order): void
    {
        $invoice = $this->om->get(InvoiceService::class)->prepareInvoice($order);
        $invoice->setRequestedCaptureCase(Invoice::CAPTURE_OFFLINE)->register();
        $this->om->create(Transaction::class)->addObject($invoice)->addObject($invoice->getOrder())->save();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function requests(string $method, string $path): array
    {
        return array_values(array_filter($this->platform->requests, static fn (array $request): bool => $request['method'] === $method && $request['path'] === $path));
    }

    /**
     * Mailpit API: GET returns the answer, DELETE clears the messages.
     *
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
