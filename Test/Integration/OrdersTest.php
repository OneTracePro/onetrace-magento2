<?php

declare(strict_types=1);

namespace OneTrace\Connector\Test\Integration;

use Magento\Newsletter\Model\SubscriberFactory;
use Magento\Sales\Api\CreditmemoManagementInterface;
use Magento\Sales\Model\Order\CreditmemoFactory;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\Service\InvoiceService;
use Magento\Framework\DB\Transaction;
use OneTrace\Connector\Test\TestCase;

final class OrdersTest extends TestCase
{
    public function testAPlacedGuestOrderIdentifiesTheCustomerAndSendsTheOrder(): void
    {
        $product = $this->product();
        $_COOKIE['cdp_aid'] = 'browser-1';
        $order = $this->order($product);
        $this->flush();

        [$identify] = $this->platform->events('identify');
        [$completed] = $this->platform->events('order_completed');

        self::assertSame(['email' => 'anna@example.com', 'phone' => '+4915112345678', 'first_name' => 'Anna', 'last_name' => 'Schmidt', 'city' => 'Berlin', 'country' => 'DE'], $identify['traits']);
        self::assertSame('browser-1', $identify['anonymousId']);
        self::assertSame('browser-1', $completed['anonymousId']);
        self::assertSame($order->getIncrementId(), $completed['properties']['order_id']);
        self::assertEquals((float) $order->getGrandTotal(), $completed['properties']['amount']);
        self::assertSame('checkmo', $completed['properties']['payment_method']);
        self::assertSame((string) $product->getId(), $completed['properties']['products'][0]['product_id']);
        self::assertSame(2, $completed['properties']['products'][0]['quantity']);
        self::assertSame('Bearer cdp_sk_test', $this->platform->requests[0]['auth']);

        foreach ($this->platform->messages() as $message) {
            self::assertContract($message);
        }
    }

    public function testFollowsInvoicesRefundsAndCancellationsInTheSameProfile(): void
    {
        $_COOKIE['cdp_aid'] = 'browser-2';
        $order = $this->order($this->product());
        $_COOKIE = [];

        $invoice = $this->om->get(InvoiceService::class)->prepareInvoice($order);
        $invoice->setRequestedCaptureCase(Invoice::CAPTURE_OFFLINE)->register();
        $this->om->create(Transaction::class)->addObject($invoice)->addObject($invoice->getOrder())->save();

        $creditmemo = $this->om->get(CreditmemoFactory::class)->createByOrder($order);
        $this->om->get(CreditmemoManagementInterface::class)->refund($creditmemo, true);

        $cancelled = $this->order($this->product());
        $cancelled->cancel()->save();
        $this->flush();

        $paid = $this->platform->events('order_paid');
        $refunded = $this->platform->events('order_refunded');

        self::assertCount(1, $paid);
        self::assertSame('browser-2', $paid[0]['anonymousId'], 'Later order events use the browser of the checkout.');
        self::assertEquals((float) $order->getGrandTotal(), $refunded[0]['properties']['amount']);
        self::assertCount(1, $this->platform->events('order_cancelled'));
        self::assertStringStartsWith('guest:', $this->platform->events('order_cancelled')[0]['anonymousId']);

        foreach ($this->platform->messages() as $message) {
            self::assertContract($message);
        }
    }

    public function testTurnsNewsletterSubscriptionsIntoConsents(): void
    {
        $email = 'news' . bin2hex(random_bytes(3)) . '@example.com';
        $subscriber = $this->om->get(SubscriberFactory::class)->create();
        $subscriber->subscribe($email);
        $subscriber->unsubscribe();
        $this->flush();

        $identify = $this->platform->events('identify');

        self::assertSame([[['channel' => 'email', 'status' => 'subscribed']], [['channel' => 'email', 'status' => 'unsubscribed']]], array_column($identify, 'consents'));
        self::assertSame($email, $identify[0]['traits']['email']);
        self::assertContract($identify[0]);
    }
}
