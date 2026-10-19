<?php

declare(strict_types=1);

namespace OneTrace\Connector\Model\Email;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Mail\TransportInterface;
use Magento\Sales\Model\Order;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use OneTrace\Connector\Model\Connection;
use OneTrace\Resource\Emails;
use Psr\Log\LoggerInterface;

/**
 * Store emails through OneTrace (POST /api/v1/emails). An email of Magento goes to each recipient either from the
 * OneTrace template of its order kind (the order data, in the language of the store view) or as is (the rendered
 * subject, HTML, text and attachments), by the settings of the project. Whatever OneTrace does not accept — an
 * error, a refusal, an unavailable platform — Magento sends itself, to those recipients only when the message
 * can be changed. One short request per recipient, without retries.
 */
class StoreEmails
{
    public function __construct(
        private readonly Settings $settings,
        private readonly Context $context,
        private readonly Kinds $kinds,
        private readonly Content $content,
        private readonly OrderData $orderData,
        private readonly Connection $connection,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly StoreManagerInterface $storeManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Sends the email of a transport through OneTrace where it can; true — Magento still has to send it (to all
     * recipients, or to the ones left in the message).
     */
    public function deliver(TransportInterface $transport): bool
    {
        $context = $this->context->of($transport);
        $storeId = isset($context['options']['store']) && is_numeric($context['options']['store']) ? (int) $context['options']['store'] : (int) $this->storeManager->getStore()->getId();

        // "Disable Email Communications": Magento's own plugin does not send it either.
        if ($this->scopeConfig->isSetFlag('system/smtp/disable', ScopeInterface::SCOPE_STORE, $storeId)) {
            return true;
        }

        $settings = $this->settings->get($storeId);

        if (!Settings::active($settings)) {
            return true;
        }

        $kind = $this->kinds->kind($context);

        // Admin user emails and notifications to the staff stay in Magento.
        if ($kind === Kinds::NATIVE || str_starts_with($kind, 'admin_')) {
            return true;
        }

        $message = $transport->getMessage();
        $recipients = $this->content->recipients($message);

        if ($recipients === []) {
            return true;
        }

        $order = ($context['vars']['order'] ?? null) instanceof Order ? $context['vars']['order'] : null;
        $customer = $order !== null ? strtolower(trim((string) $order->getCustomerEmail())) : null;
        $content = false;
        $orderData = null;
        $native = [];

        foreach ($recipients as [$address, $header]) {
            // Copies to the staff (Send Order Email Copy To, Bcc and Cc) always stay in Magento: through OneTrace they
            // would make customer profiles of staff addresses.
            $staff = $customer !== null ? $address !== $customer : $header !== 'to';
            $route = $staff ? Emails::NATIVE : Emails::route($settings, $kind);

            if ($route === Emails::TEMPLATE && $order === null) {
                $route = $settings['relay'] ? Emails::RELAY : Emails::NATIVE;
            }

            if ($route === Emails::NATIVE) {
                $native[] = $address;

                continue;
            }

            try {
                if ($content === false) {
                    $content = $this->content->read($message);
                }

                $email = [
                    'to' => $address,
                    'kind' => $kind,
                    'language' => $this->orderData->language($storeId),
                    'traits' => $this->traits($context['vars'] ?? [], $order),
                ];

                if ($route === Emails::TEMPLATE && $order !== null) {
                    $orderData ??= $this->orderData->build($order, $kind, $context['vars'] ?? []);
                    $email['order'] = $orderData;

                    // With relay on the shop's email goes as is if the template was turned off a moment ago.
                    if ($settings['relay'] && is_array($content)) {
                        $email += $content;
                    } elseif (isset($content['attachments'])) {
                        $email['attachments'] = $content['attachments'];
                    }
                } elseif ($route === Emails::RELAY && is_array($content)) {
                    $email += $content;
                } else {
                    $native[] = $address;

                    continue;
                }
            } catch (\Throwable $error) {
                $this->logger->error('OneTrace: store email ' . $kind . ': ' . $error->getMessage());
                $native[] = $address;

                continue;
            }

            if (!$this->send($storeId, $email)) {
                $native[] = $address;
            }
        }

        if ($native === []) {
            return false;
        }

        if (count($native) < count($recipients) && !$this->content->keepRecipients($message, $native)) {
            $this->logger->warning('OneTrace: store email: could not leave only the recipients OneTrace did not accept, Magento sends it to all of them.');
        }

        return true;
    }

    /**
     * An order email without a Magento email (order_delivered, order_cancelled): sent only when its OneTrace
     * template is on; once per order and kind (the idempotency key).
     */
    public function sendOrderEmail(Order $order, string $kind): bool
    {
        $storeId = (int) $order->getStoreId();
        $to = trim((string) $order->getCustomerEmail());

        if ($to === '' || $this->scopeConfig->isSetFlag('system/smtp/disable', ScopeInterface::SCOPE_STORE, $storeId)) {
            return false;
        }

        $settings = $this->settings->get($storeId);

        if ($settings === null || Emails::route($settings, $kind) !== Emails::TEMPLATE) {
            return false;
        }

        try {
            $payload = [
                'to' => $to,
                'kind' => $kind,
                'language' => $this->orderData->language($storeId),
                'order' => $this->orderData->build($order, $kind),
                'traits' => $this->traits([], $order),
            ];
        } catch (\Throwable $error) {
            $this->logger->error('OneTrace: ' . $kind . ' email of order ' . $order->getIncrementId() . ': ' . $error->getMessage());

            return false;
        }

        return $this->send($storeId, $payload, 'magento-order-' . $order->getId() . '-' . $kind);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function send(int $storeId, array $payload, ?string $idempotencyKey = null): bool
    {
        try {
            $this->connection->client($storeId, 'email')->emails()->send(array_filter($payload, static fn ($value): bool => $value !== null && $value !== [] && $value !== ''), $idempotencyKey);

            return true;
        } catch (\Throwable $error) {
            $this->logger->warning('OneTrace: store email ' . $payload['kind'] . ' was not accepted, Magento sends it: ' . $error->getMessage());

            return false;
        }
    }

    /**
     * The customer's first name, written to the profile before the email (a new customer's profile is created by it).
     *
     * @param array<string, mixed> $vars
     *
     * @return array<string, string>
     */
    private function traits(array $vars, ?Order $order): array
    {
        $name = '';

        if ($order !== null) {
            $name = (string) ($order->getBillingAddress()?->getFirstname() ?: $order->getCustomerFirstname());
        } elseif (($vars['customer'] ?? null) instanceof \Magento\Framework\DataObject) {
            // CustomerSecure of customer account emails.
            $name = (string) $vars['customer']->getData('firstname');
        } elseif (($vars['customer'] ?? null) instanceof \Magento\Customer\Api\Data\CustomerInterface) {
            $name = (string) $vars['customer']->getFirstname();
        }

        return trim($name) !== '' ? ['first_name' => trim($name)] : [];
    }
}
