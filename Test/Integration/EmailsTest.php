<?php

declare(strict_types=1);

namespace OneTrace\Connector\Test\Integration;

use Magento\Customer\Api\AccountManagementInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Customer\Model\AccountManagement;
use Magento\Framework\App\Area;
use Magento\Framework\Event\Observer;
use Magento\Sales\Api\Data\ShipmentTrackCreationInterfaceFactory;
use Magento\Sales\Api\InvoiceOrderInterface;
use Magento\Sales\Api\OrderManagementInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Api\RefundOrderInterface;
use Magento\Sales\Api\ShipOrderInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use OneTrace\Connector\Model\Email\Kinds;
use OneTrace\Connector\Model\Email\Settings;
use OneTrace\Connector\Observer\SettingsSaved;
use OneTrace\Connector\Test\TestCase;
use OneTrace\Http\Request;
use OneTrace\Http\Response;

/**
 * Store emails through OneTrace: Magento sends its emails for real (SMTP to Mailpit of the image), so what goes
 * natively and what does not is seen in Mailpit.
 */
final class EmailsTest extends TestCase
{
    protected const SETTINGS = [
        'system/smtp/disable' => 0,
        'system/smtp/transport' => 'smtp',
        'system/smtp/host' => '127.0.0.1',
        'system/smtp/port' => 1025,
    ] + parent::SETTINGS;

    private const MAILPIT = 'http://127.0.0.1:8025/api/v1/messages';

    /** @var array<string, mixed> */
    private array $emailSettings = ['relay' => false, 'integration_id' => null, 'templates' => []];

    /** Answer of POST /emails: [status, body]. */
    private array $emailAnswer = [202, ['id' => 1, 'status' => 'queued']];

    protected function setUp(): void
    {
        parent::setUp();
        $this->om->get(Settings::class)->clean();
        $this->platform->responder = function (Request $request): ?Response {
            $path = (string) parse_url($request->getUrl(), PHP_URL_PATH);

            if ($path === '/api/v1/emails/settings') {
                return is_int($this->emailSettings['status'] ?? null)
                    ? new Response($this->emailSettings['status'], ['content-type' => 'application/json'], '{"message":"No"}')
                    : new Response(200, ['content-type' => 'application/json'], (string) json_encode($this->emailSettings));
            }

            if ($path === '/api/v1/emails') {
                return new Response($this->emailAnswer[0], ['content-type' => 'application/json'], (string) json_encode($this->emailAnswer[1]));
            }

            return null;
        };
        $this->mailpit('DELETE');
    }

    protected function tearDown(): void
    {
        $this->om->get(Settings::class)->clean();
        $writer = $this->om->get(\Magento\Config\Model\ResourceModel\Config::class);
        $writer->saveConfig('system/smtp/disable', '1');
        $writer->deleteConfig('sales_email/order/copy_to');
        $writer->deleteConfig('sales_email/order/copy_method');
        $this->reinit();
        parent::tearDown();
    }

    public function testKindsOfMagentoEmailTemplates(): void
    {
        $kinds = $this->om->get(Kinds::class);
        $order = $this->om->create(Order::class);
        $shipment = $this->om->create(Order\Shipment::class);
        $context = static fn (string $identifier, array $vars = [], string $area = Area::AREA_FRONTEND): array => ['identifier' => $identifier, 'vars' => $vars, 'options' => ['area' => $area, 'store' => 1]];

        self::assertSame('order_placed', $kinds->kind($context('sales_email_order_guest_template')));
        self::assertSame('order_paid', $kinds->kind($context('sales_email_invoice_template')));
        self::assertSame('order_shipped', $kinds->kind($context('sales_email_shipment_guest_template')));
        self::assertSame('order_refunded', $kinds->kind($context('sales_email_creditmemo_template')));
        self::assertSame('order_note', $kinds->kind($context('sales_email_shipment_comment_template')));
        self::assertSame('password_reset', $kinds->kind($context('customer_password_forgot_email_template')));
        self::assertSame('account_new', $kinds->kind($context('customer_create_account_email_template')));
        self::assertSame('email_verification', $kinds->kind($context('customer_create_account_email_confirmation_template')));
        self::assertSame('admin_contact_form', $kinds->kind($context('contact_email_email_template')));
        self::assertSame('other', $kinds->kind($context('some_extension_template')));
        self::assertSame('other', $kinds->kind(null), 'Not built by the TransportBuilder.');
        self::assertSame('order_shipped', $kinds->kind($context('some_extension_template', ['order' => $order, 'shipment' => $shipment])), 'By the order of the variables.');
        self::assertNull($kinds->kind($context('admin_emails_forgot_email_template', [], Area::AREA_ADMINHTML)), 'Admin user emails stay in Magento.');
        self::assertNull($kinds->kind($context('some_extension_template', [], Area::AREA_ADMINHTML)));

        // A custom template of Marketing → Email Templates made from a file template.
        $template = $this->om->create(\Magento\Email\Model\Template::class);
        $template->setTemplateCode('onetrace-' . bin2hex(random_bytes(4)))->setTemplateText('{{var customer.name}}')->setTemplateSubject('Reset')
            ->setTemplateType(2)->setData('orig_template_code', 'customer_password_remind_email_template');
        $template->getResource()->save($template);

        try {
            self::assertSame('password_reset', $kinds->kind($context((string) $template->getId())));
        } finally {
            $template->getResource()->delete($template);
        }
    }

    public function testSettingsAreCachedAndReadAgainWhenTheModuleSettingsAreSaved(): void
    {
        $settings = $this->om->get(Settings::class);
        $this->emailSettings = ['relay' => true, 'integration_id' => 4, 'templates' => ['order_placed' => true, 'order_paid' => false]];

        self::assertSame(['relay' => true, 'integration_id' => 4, 'templates' => ['order_placed' => true, 'order_paid' => false]], $settings->get(1));
        self::assertSame($settings->get(1), $this->freshSettings()->get(1), 'From the Magento cache in another request.');
        self::assertCount(1, $this->requests('GET', '/api/v1/emails/settings'));

        $this->emailSettings = ['relay' => false, 'integration_id' => null, 'templates' => []];
        $this->om->get(SettingsSaved::class)->execute(new Observer());
        $this->om->get(\OneTrace\Connector\Model\Connection::class)->reset(['transport' => $this->platform]);

        self::assertFalse($settings->get(1)['relay']);
        self::assertCount(2, $this->requests('GET', '/api/v1/emails/settings'));

        // A key without messages.send: native, and not asked again for every email.
        $this->emailSettings = ['status' => 403];
        $settings->clean();

        self::assertNull($settings->get(1));
        self::assertNull($this->freshSettings()->get(1));
        self::assertCount(3, $this->requests('GET', '/api/v1/emails/settings'));
    }

    public function testRelaysRegistrationAndPasswordResetEmailsAsTheyAre(): void
    {
        $this->emailSettings = ['relay' => true, 'integration_id' => null, 'templates' => ['order_placed' => true]];
        $email = 'maria.' . bin2hex(random_bytes(3)) . '@example.com';
        $this->customer($email);
        $this->om->get(AccountManagementInterface::class)->initiatePasswordReset($email, AccountManagement::EMAIL_RESET, 1);

        $sent = $this->sentEmails();

        self::assertSame(['account_new', 'password_reset'], array_column($sent, 'kind'));

        foreach ($sent as $body) {
            self::assertSame($email, $body['to']);
            self::assertSame('en-US', $body['language']);
            self::assertSame(['first_name' => 'Maria'], $body['traits']);
            self::assertNotSame('', $body['subject']);
            self::assertArrayHasKey('from_name', $body);
            self::assertArrayNotHasKey('order', $body);
        }

        self::assertStringContainsString('customer/account/createPassword', $sent[1]['html'], 'The rendered email of the store.');
        self::assertSame([], $this->mailpit(), 'Magento did not send them itself.');
    }

    public function testOrderEmailFromTheOneTraceTemplateAndTheStaffCopyNatively(): void
    {
        $this->emailSettings = ['relay' => false, 'integration_id' => null, 'templates' => ['order_placed' => true]];
        $writer = $this->om->get(\Magento\Config\Model\ResourceModel\Config::class);
        $writer->saveConfig('sales_email/order/copy_to', 'staff@example.com');
        $writer->saveConfig('sales_email/order/copy_method', 'bcc');
        $this->reinit();

        $order = $this->emailed($this->order($this->product(['name' => 'Sneakers', 'price' => 40.0]), 2));

        $sent = $this->sentEmails();

        self::assertCount(1, $sent);
        self::assertSame('anna@example.com', $sent[0]['to']);
        self::assertSame('order_placed', $sent[0]['kind']);
        self::assertSame('en-US', $sent[0]['language']);
        self::assertSame(['first_name' => 'Anna'], $sent[0]['traits']);
        self::assertArrayNotHasKey('html', $sent[0], 'Relay is off: OneTrace builds it.');

        $data = $sent[0]['order'];
        self::assertSame($order->getIncrementId(), $data['number']);
        self::assertSame((string) $order->getOrderCurrencyCode(), $data['currency']);
        self::assertEquals((float) $order->getGrandTotal(), $data['amount']);
        self::assertEquals(80.0, $data['subtotal']);
        self::assertSame('Check / Money order', $data['payment_method']);
        self::assertStringContainsString('Flat Rate', $data['shipping_method']);
        self::assertStringContainsString('sales/guest/form', $data['url']);
        self::assertStringNotContainsString('/admin', $data['url'], 'A storefront link.');
        self::assertSame(['name' => 'Anna Schmidt', 'address1' => 'Torstraße 1', 'city' => 'Berlin', 'postcode' => '10119', 'country' => 'Germany', 'phone' => '+49 151 1234-5678'], $data['shipping_address']);
        self::assertSame('Sneakers', $data['products'][0]['product_name']);
        self::assertSame(2, $data['products'][0]['quantity']);
        self::assertEquals(40.0, $data['products'][0]['price']);
        self::assertEquals(80.0, $data['products'][0]['total']);
        self::assertStringStartsWith('onetrace-', $data['products'][0]['sku']);
        self::assertStringContainsString($data['products'][0]['sku'], $data['products'][0]['url']);

        // The Bcc copy to the staff: Magento sends it, to the staff only.
        $mails = $this->mailpit();
        self::assertCount(1, $mails);
        self::assertSame(['staff@example.com'], $this->addresses($mails[0]));
    }

    public function testEmailsToTheStaffAlwaysStayInMagentoAlsoWithRelayOn(): void
    {
        $this->emailSettings = ['relay' => true, 'integration_id' => null, 'templates' => []];
        $writer = $this->om->get(\Magento\Config\Model\ResourceModel\Config::class);
        $writer->saveConfig('sales_email/order/copy_to', 'staff@example.com,manager@example.com');
        $writer->saveConfig('sales_email/order/copy_method', 'copy');
        $this->reinit();

        $this->emailed($this->order($this->product()));

        // The customer's email is relayed; the separate copies (Send Order Email Copy To, "Separate Email") are not.
        $sent = $this->sentEmails();
        self::assertSame(['anna@example.com'], array_column($sent, 'to'));
        self::assertSame('order_placed', $sent[0]['kind']);
        $mails = $this->mailpit();
        $staff = array_merge(...array_map(fn (array $mail): array => $this->addresses($mail), $mails));
        sort($staff);
        self::assertSame(['manager@example.com', 'staff@example.com'], $staff);

        // A notification to the store owner (the contact form).
        $this->mailpit('DELETE');
        $native = 0;
        $transport = $this->transport('contact_email_email_template', ['data' => new \Magento\Framework\DataObject(['name' => 'Lena', 'email' => 'lena@example.com', 'telephone' => '', 'comment' => 'Hello'])], 'owner@example.com');
        $this->om->get(\OneTrace\Connector\Plugin\SendThroughOneTrace::class)->aroundSendMessage($transport, static function () use (&$native): void {
            ++$native;
        });

        self::assertSame(1, $native);
        self::assertCount(1, $this->sentEmails(), 'Nothing more through OneTrace.');
    }

    public function testMagentoSendsTheEmailWhenOneTraceDoesNotAcceptIt(): void
    {
        $this->emailSettings = ['relay' => true, 'integration_id' => null, 'templates' => ['order_placed' => true]];
        $this->emailAnswer = [402, ['message' => 'The message quota is used up.', 'code' => 'quota_exceeded']];

        $this->emailed($this->order($this->product()));

        self::assertCount(1, $this->sentEmails());
        $mails = $this->mailpit();
        self::assertCount(1, $mails);
        self::assertSame(['anna@example.com'], $this->addresses($mails[0]));

        // The platform does not answer: the same.
        $this->mailpit('DELETE');
        $this->emailAnswer = [503, ['message' => 'Unavailable']];
        $order = $this->emailed($this->order($this->product(), 1, 'ben@example.com'));

        self::assertSame(['ben@example.com'], $this->addresses($this->mailpit()[0]));
        self::assertTrue((bool) $this->om->create(Order::class)->load($order->getId())->getEmailSent());
    }

    public function testShipmentAndCreditMemoEmailsFromTemplatesWithTrackingAndRefund(): void
    {
        $this->emailSettings = ['relay' => false, 'integration_id' => null, 'templates' => ['order_shipped' => true, 'order_refunded' => true]];
        $order = $this->order($this->product(['price' => 30.0]), 2);
        $item = (int) $order->getAllVisibleItems()[0]->getItemId();
        $this->mailpit('DELETE');

        $track = $this->om->get(ShipmentTrackCreationInterfaceFactory::class)->create()->setTitle('DHL Express')->setCarrierCode('custom')->setTrackNumber('JD0146 0001');
        $line = $this->om->get(\Magento\Sales\Api\Data\ShipmentItemCreationInterfaceFactory::class)->create()->setOrderItemId($item)->setQty(1);
        $this->om->get(ShipOrderInterface::class)->execute((int) $order->getId(), [$line], true, false, null, [$track]);

        $this->om->get(InvoiceOrderInterface::class)->execute((int) $order->getId());
        $this->om->get(RefundOrderInterface::class)->execute((int) $order->getId(), [], true);

        $sent = array_values(array_filter($this->sentEmails(), static fn (array $body): bool => $body['kind'] !== 'order_placed'));

        self::assertSame(['order_shipped', 'order_refunded'], array_column($sent, 'kind'));
        self::assertSame('DHL Express', $sent[0]['order']['carrier']);
        self::assertSame('JD0146 0001', $sent[0]['order']['tracking_number']);
        self::assertStringContainsString('shipping/tracking/popup', $sent[0]['order']['tracking_url']);
        self::assertSame(1, $sent[0]['order']['products'][0]['quantity'], 'The products of the parcel.');
        self::assertEquals((float) $order->getGrandTotal(), $sent[1]['order']['refund_amount']);
        self::assertSame([], $this->mailpit(), 'Both from templates, nothing native.');
    }

    public function testDeliveredAndCancelledOrderEmailsWithoutMagentoEmails(): void
    {
        $this->emailSettings = ['relay' => false, 'integration_id' => null, 'templates' => ['order_delivered' => true, 'order_cancelled' => true]];
        $writer = $this->om->get(\Magento\Config\Model\ResourceModel\Config::class);
        $writer->saveConfig('onetrace/parts/delivered_statuses', 'delivered');
        $this->reinit();

        try {
            $delivered = $this->order($this->product());
            $repository = $this->om->create(OrderRepositoryInterface::class);
            $repository->save($this->om->create(Order::class)->load($delivered->getId())->setStatus('delivered'));
            $repository->save($this->om->create(Order::class)->load($delivered->getId())->setStatus('processing'));
            $repository->save($this->om->create(Order::class)->load($delivered->getId())->setStatus('delivered'));

            $cancelled = $this->order($this->product());
            $this->om->get(OrderManagementInterface::class)->cancel((int) $cancelled->getId());
        } finally {
            $writer->deleteConfig('onetrace/parts/delivered_statuses');
            $this->reinit();
        }

        $requests = array_values(array_filter($this->requests('POST', '/api/v1/emails'), static fn (array $request): bool => in_array($request['body']['kind'], ['order_delivered', 'order_cancelled'], true)));

        self::assertCount(2, $requests, 'Delivered once.');
        self::assertSame('order_delivered', $requests[0]['body']['kind']);
        self::assertSame($delivered->getIncrementId(), $requests[0]['body']['order']['number']);
        self::assertSame('magento-order-' . $delivered->getId() . '-order_delivered', $requests[0]['idempotency_key']);
        self::assertSame('order_cancelled', $requests[1]['body']['kind']);
        self::assertSame($cancelled->getIncrementId(), $requests[1]['body']['order']['number']);
    }

    public function testNothingGoesThroughOneTraceWithoutTheFeature(): void
    {
        // The key has no messages.send permission.
        $this->emailSettings = ['status' => 403];
        $this->emailed($this->order($this->product()));

        self::assertSame([], $this->sentEmails());
        self::assertCount(1, $this->mailpit());

        // Turned off in the platform.
        $this->mailpit('DELETE');
        $this->om->get(Settings::class)->clean();
        $this->emailSettings = ['relay' => false, 'integration_id' => null, 'templates' => ['order_placed' => false]];
        $this->emailed($this->order($this->product()));

        self::assertSame([], $this->sentEmails());
        self::assertCount(1, $this->mailpit());
    }

    public function testRelaysAttachmentsOfAllowedTypesAndLeavesOtherFilesToMagento(): void
    {
        $this->emailSettings = ['relay' => true, 'integration_id' => null, 'templates' => []];
        $plugin = $this->om->get(\OneTrace\Connector\Plugin\SendThroughOneTrace::class);
        $native = 0;
        $proceed = static function () use (&$native): void {
            ++$native;
        };

        $plugin->aroundSendMessage($this->withAttachment($this->transport(), 'invoice-1001.pdf', 'application/octet-stream', '%PDF-1.4 invoice'), $proceed);

        $sent = $this->sentEmails();
        self::assertSame(0, $native);
        self::assertSame('newsletter_subscribed', $sent[0]['kind']);
        self::assertSame([['name' => 'invoice-1001.pdf', 'type' => 'application/pdf', 'content' => base64_encode('%PDF-1.4 invoice')]], $sent[0]['attachments'], 'The type by the extension of an octet-stream file.');
        self::assertStringContainsString('<', $sent[0]['html']);

        $plugin->aroundSendMessage($this->withAttachment($this->transport(), 'export.xml', 'application/xml', '<xml/>'), $proceed);

        self::assertSame(1, $native, 'A file the platform does not take: Magento sends the email.');
        self::assertCount(1, $this->sentEmails());
    }

    public function testTheSettingsPageShowsTheStateOfStoreEmails(): void
    {
        $block = $this->om->create(\OneTrace\Connector\Block\Adminhtml\StoreEmails::class);
        $this->emailSettings = ['relay' => true, 'integration_id' => null, 'templates' => ['order_placed' => true, 'order_shipped' => true, 'order_paid' => false]];

        $lines = $block->lines(1);

        self::assertSame('Store emails go through OneTrace as they are.', $lines[0]);
        self::assertSame('Order emails from OneTrace templates: order_placed, order_shipped.', $lines[1]);

        $this->om->get(Settings::class)->clean();
        $this->emailSettings = ['status' => 403];

        self::assertStringStartsWith('Not available: the secret key needs the messages.send permission', $block->lines(1)[0]);
    }

    /** The order confirmation, as the storefront and the REST API send it after the checkout (the admin area does not). */
    private function emailed(Order $order): Order
    {
        $this->om->get(OrderSender::class)->send($order, true);

        return $order;
    }

    /**
     * A transport of a Magento email made by the TransportBuilder: the newsletter subscription success email by default.
     *
     * @param array<string, mixed>|null $vars
     */
    private function transport(string $identifier = 'newsletter_subscription_success_email_template', ?array $vars = null, string $to = 'lena@example.com'): \Magento\Framework\Mail\TransportInterface
    {
        $vars ??= ['subscriber' => $this->om->create(\Magento\Newsletter\Model\Subscriber::class)->setSubscriberEmail('lena@example.com')->setStoreId(1)];

        return $this->om->create(\Magento\Framework\Mail\Template\TransportBuilder::class)
            ->setTemplateIdentifier($identifier)
            ->setTemplateOptions(['area' => Area::AREA_FRONTEND, 'store' => 1])
            ->setTemplateVars($vars)
            ->setFromByScope('general', 1)
            ->addTo($to)
            ->getTransport();
    }

    /**
     * An attachment as extensions add it: to the Symfony message (2.4.8+) or to the Laminas MIME body (2.4.7).
     */
    private function withAttachment(\Magento\Framework\Mail\TransportInterface $transport, string $name, string $type, string $contents): \Magento\Framework\Mail\TransportInterface
    {
        $message = $transport->getMessage();

        if (method_exists($message, 'getSymfonyMessage')) {
            $symfony = $message->getSymfonyMessage();
            $symfony->setBody(new \Symfony\Component\Mime\Part\Multipart\MixedPart($symfony->getBody(), new \Symfony\Component\Mime\Part\DataPart($contents, $name, $type)));

            return $transport;
        }

        $laminas = (new \ReflectionProperty(\Magento\Framework\Mail\Message::class, 'zendMessage'))->getValue($message);
        $part = new \Laminas\Mime\Part($contents);
        $part->setType($type)->setDisposition('attachment')->setFileName($name)->setEncoding('base64');
        $laminas->getBody()->addPart($part);

        return $transport;
    }

    private function customer(string $email): CustomerInterface
    {
        $customer = $this->om->create(CustomerInterface::class)->setEmail($email)->setFirstname('Maria')->setLastname('Weber')->setWebsiteId(1)->setStoreId(1)->setGroupId(1);

        return $this->om->get(AccountManagementInterface::class)->createAccount($customer, 'Secret-12345');
    }

    /** A settings reader of another request: only the Magento cache is shared. */
    private function freshSettings(): Settings
    {
        return $this->om->create(Settings::class);
    }

    /**
     * Bodies of POST /emails.
     *
     * @return list<array<string, mixed>>
     */
    private function sentEmails(): array
    {
        return array_column($this->requests('POST', '/api/v1/emails'), 'body');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function requests(string $method, string $path): array
    {
        return array_values(array_filter($this->platform->requests, static fn (array $request): bool => $request['method'] === $method && $request['path'] === $path));
    }

    /**
     * Messages Magento sent itself (Mailpit), or clears them with DELETE.
     *
     * @return list<array<string, mixed>>
     */
    private function mailpit(string $method = 'GET'): array
    {
        $context = stream_context_create(['http' => ['method' => $method, 'ignore_errors' => true, 'timeout' => 5]]);
        $body = @file_get_contents(self::MAILPIT, false, $context);

        if ($method !== 'GET') {
            return [];
        }

        self::assertIsString($body, 'Mailpit is running (tests/env/run.sh starts it).');

        return (array) (json_decode($body, true)['messages'] ?? []);
    }

    /**
     * @param array<string, mixed> $mail
     *
     * @return list<string>
     */
    private function addresses(array $mail): array
    {
        $addresses = [];

        foreach (['To', 'Cc', 'Bcc'] as $field) {
            foreach ((array) ($mail[$field] ?? []) as $address) {
                $addresses[] = strtolower((string) $address['Address']);
            }
        }

        return $addresses;
    }
}
