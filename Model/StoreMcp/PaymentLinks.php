<?php

declare(strict_types=1);

namespace OneTrace\Connector\Model\StoreMcp;

use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Sales\Model\Order;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Links to the payment page of an order placed through the store MCP: GET /onetrace/order/pay with the order id, the
 * expiry and an HMAC of both with the encryption key of Magento. The page shows the order and the payment
 * instructions of its payment method to anyone with the link, guests too, for 30 days.
 */
class PaymentLinks
{
    public const TTL = 30 * 86400;

    public function __construct(
        private readonly EncryptorInterface $encryptor,
        private readonly StoreManagerInterface $storeManager,
    ) {
    }

    public function url(Order $order, ?int $now = null): string
    {
        $expires = ($now ?? time()) + self::TTL;
        $id = (int) $order->getId();
        /** @var Store $store */
        $store = $this->storeManager->getStore((int) $order->getStoreId());

        return $store->getUrl('onetrace/order/pay', [
            '_secure' => true,
            '_nosid' => true,
            '_query' => ['order' => $id, 'expires' => $expires, 'signature' => $this->signature($id, $expires)],
        ]);
    }

    /**
     * The order id of a valid link that has not expired, otherwise null.
     */
    public function verify(mixed $order, mixed $expires, mixed $signature): ?int
    {
        if (!is_string($order) || !ctype_digit($order) || !is_string($expires) || !ctype_digit($expires) || !is_string($signature) || (int) $expires < time()) {
            return null;
        }

        return hash_equals($this->signature((int) $order, (int) $expires), $signature) ? (int) $order : null;
    }

    private function signature(int $orderId, int $expires): string
    {
        return $this->encryptor->hash('onetrace-order-pay|' . $orderId . '|' . $expires);
    }
}
