<?php

declare(strict_types=1);

namespace OneTrace\Connector\Model;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Locale\ResolverInterface;
use Magento\Sales\Model\Order as SalesOrder;
use Magento\Sales\Model\Order\Creditmemo;
use Magento\Sales\Model\Order\Shipment;
use OneTrace\Commerce\Customer;
use OneTrace\Commerce\LineItem;
use OneTrace\Commerce\Order;

/**
 * Magento customers and orders in the terms of the common e-commerce contract.
 */
class Mapper
{
    public function __construct(
        private readonly CustomerRepositoryInterface $customers,
        private readonly Connection $connection,
        private readonly Catalog $catalog,
        private readonly ResourceConnection $resource,
        private readonly ResolverInterface $locale,
    ) {
    }

    /**
     * A customer account with the default billing address; the tracker cookie of the current request, if any.
     */
    public function customer(CustomerInterface|int $customer): Customer
    {
        $customer = is_int($customer) ? $this->customers->getById($customer) : $customer;
        $billing = null;

        foreach ((array) $customer->getAddresses() as $address) {
            if ($address->isDefaultBilling() || $billing === null) {
                $billing = $address;
            }
        }

        return (new Customer((string) $customer->getId(), $this->connection->anonymousId()))
            ->email($customer->getEmail())
            ->phone($billing?->getTelephone())
            ->name($customer->getFirstname(), $customer->getLastname())
            ->city($billing?->getCity())
            ->country($billing?->getCountryId())
            ->language($this->locale->getLocale());
    }

    /**
     * Customer of an order: the account if there is one, otherwise a guest found by email and phone; the browser
     * of the checkout, saved with the order.
     */
    public function orderCustomer(SalesOrder $order): Customer
    {
        $billing = $order->getBillingAddress();
        $userId = $order->getCustomerIsGuest() ? null : (string) $order->getCustomerId();

        return (new Customer($userId ?: null, $this->orderVisitor((int) $order->getId())))
            ->email($order->getCustomerEmail())
            ->phone($billing?->getTelephone())
            ->name($billing?->getFirstname() ?: $order->getCustomerFirstname(), $billing?->getLastname() ?: $order->getCustomerLastname())
            ->city($billing?->getCity())
            ->country($billing?->getCountryId());
    }

    public function order(SalesOrder $order): Order
    {
        $created = $order->getCreatedAt() ? new \DateTimeImmutable((string) $order->getCreatedAt(), new \DateTimeZone('UTC')) : new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $shipping = (string) $order->getShippingMethod();

        return (new Order(
            (string) $order->getIncrementId(),
            (float) $order->getGrandTotal(),
            (string) $order->getOrderCurrencyCode(),
            $created,
            $this->orderCustomer($order),
            $this->lines($order->getAllVisibleItems())
        ))->with([
            'subtotal' => (float) $order->getSubtotal(),
            'shipping' => (float) $order->getShippingAmount(),
            'tax' => (float) $order->getTaxAmount(),
            'discount' => abs((float) $order->getDiscountAmount()),
            'coupon' => (string) $order->getCouponCode(),
            'payment_method' => (string) $order->getPayment()?->getMethod(),
            'shipping_method' => $shipping,
        ]);
    }

    /**
     * Refunded products of a credit memo.
     *
     * @return list<LineItem>
     */
    public function refundLines(Creditmemo $creditmemo): array
    {
        $lines = [];

        foreach ($creditmemo->getAllItems() as $item) {
            $orderItem = $item->getOrderItem();

            if ($orderItem === null || $orderItem->getParentItemId() || (float) $item->getQty() <= 0) {
                continue;
            }

            $lines[] = $this->line($orderItem, (float) $item->getRowTotal() - (float) $item->getDiscountAmount(), (int) $item->getQty());
        }

        return $lines;
    }

    /**
     * Shipped products of a shipment, priced as in the order.
     *
     * @return list<LineItem>
     */
    public function shipmentLines(Shipment $shipment): array
    {
        $lines = [];

        foreach ($shipment->getAllItems() as $item) {
            $orderItem = $item->getOrderItem();
            $quantity = (int) $item->getQty();

            if ($orderItem === null || $orderItem->getParentItemId() || $quantity <= 0) {
                continue;
            }

            $total = ((float) $orderItem->getRowTotal() - (float) $orderItem->getDiscountAmount()) / max(1.0, (float) $orderItem->getQtyOrdered()) * $quantity;
            $lines[] = $this->line($orderItem, $total, $quantity);
        }

        return $lines;
    }

    public function rememberVisitor(int $orderId, string $anonymousId): void
    {
        $this->resource->getConnection()->insertOnDuplicate($this->resource->getTableName('onetrace_order'), ['order_id' => $orderId, 'anonymous_id' => $anonymousId], ['anonymous_id']);
    }

    private function orderVisitor(int $orderId): ?string
    {
        $db = $this->resource->getConnection();
        $value = $db->fetchOne($db->select()->from($this->resource->getTableName('onetrace_order'), ['anonymous_id'])->where('order_id = ?', $orderId));

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @param array<\Magento\Sales\Model\Order\Item> $items visible items: configurable parents carry the price
     *
     * @return list<LineItem>
     */
    private function lines(array $items): array
    {
        $lines = [];

        foreach ($items as $item) {
            $lines[] = $this->line($item, (float) $item->getRowTotal() - (float) $item->getDiscountAmount(), (int) $item->getQtyOrdered());
        }

        return $lines;
    }

    private function line(\Magento\Sales\Model\Order\Item $item, float $total, int $quantity): LineItem
    {
        $children = $item->getChildrenItems();
        $variant = $children !== [] ? (int) reset($children)->getProductId() : null;
        $product = $item->getProduct();

        return new LineItem(
            (int) $item->getProductId(),
            (string) $item->getName(),
            max(0.0, $total) / max(1, $quantity),
            max(1, $quantity),
            $variant,
            $product instanceof \Magento\Catalog\Model\Product ? $this->catalog->categoryId($product) : null
        );
    }
}
