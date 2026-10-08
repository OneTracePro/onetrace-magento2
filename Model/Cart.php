<?php

declare(strict_types=1);

namespace OneTrace\Connector\Model;

use Magento\Customer\Model\Session as CustomerSession;
use Magento\Quote\Model\Quote\Item;
use OneTrace\Commerce\Customer;
use OneTrace\Commerce\LineItem;

/**
 * Cart events from the server: sent only when the visitor can be linked to a profile — the tracker cookie or a
 * signed-in customer.
 */
class Cart
{
    public function __construct(
        private readonly Config $config,
        private readonly Connection $connection,
        private readonly CustomerSession $session,
        private readonly Catalog $catalog,
    ) {
    }

    public function customer(int $storeId): ?Customer
    {
        if (!$this->config->enabled('cart', $storeId) || !$this->config->server($storeId)) {
            return null;
        }

        $customerId = $this->session->isLoggedIn() ? (string) $this->session->getCustomerId() : null;
        $anonymousId = $this->connection->anonymousId();

        return $customerId !== null || $anonymousId !== null ? new Customer($customerId, $anonymousId) : null;
    }

    /**
     * A cart line: the parent product of a configurable one with the chosen variant.
     */
    public function line(Item $item, ?int $quantity = null): LineItem
    {
        $item = $item->getParentItem() ?: $item;
        $children = $item->getChildren();
        $variant = $children !== [] ? (int) reset($children)->getProductId() : null;
        $product = $item->getProduct();

        return new LineItem(
            (int) $item->getProductId(),
            (string) $item->getName(),
            (float) ($item->getCalculationPrice() ?? $item->getPrice()),
            max(1, $quantity ?? (int) $item->getQty()),
            $variant,
            $product ? $this->catalog->categoryId($product) : null
        );
    }
}
