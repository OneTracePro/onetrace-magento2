<?php

declare(strict_types=1);

namespace OneTrace\Connector\Model;

use Magento\Catalog\Model\Product;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Area;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\State;
use Magento\Store\Model\StoreManagerInterface;
use OneTrace\Commerce\Customer;
use OneTrace\Commerce\LineItem;

/**
 * Wishlist events from the server (add_to_wishlist, remove_from_wishlist) with the "Cart" setting: the wishlist
 * belongs to a customer, so the customer id is always there; the tracker cookie links the browser on the
 * storefront. remove_from_wishlist is sent only when no other item of the product is left in the wishlist
 * (changing the options of an item replaces it with a new one).
 */
class Wishlist
{
    /** @var array<int, true> items already reported in this request */
    private array $added = [];

    public function __construct(
        private readonly Config $config,
        private readonly Connection $connection,
        private readonly Queue $queue,
        private readonly Catalog $catalog,
        private readonly CustomerSession $session,
        private readonly StoreManagerInterface $storeManager,
        private readonly ResourceConnection $resource,
        private readonly State $state,
    ) {
    }

    public function added(\Magento\Wishlist\Model\Item $item): void
    {
        if (isset($this->added[(int) $item->getId()])) {
            return;
        }

        $this->added[(int) $item->getId()] = true;
        $this->send('add_to_wishlist', $item);
    }

    public function removed(\Magento\Wishlist\Model\Item $item): void
    {
        $db = $this->resource->getConnection();
        $left = $db->fetchOne($db->select()
            ->from($this->resource->getTableName('wishlist_item'), ['COUNT(*)'])
            ->where('wishlist_id = ?', (int) $item->getWishlistId())
            ->where('product_id = ?', (int) $item->getProductId())
            ->where('wishlist_item_id != ?', (int) $item->getId()));

        if ((int) $left === 0) {
            $this->send('remove_from_wishlist', $item);
        }
    }

    private function send(string $event, \Magento\Wishlist\Model\Item $item): void
    {
        $storeId = (int) $item->getStoreId();
        $customer = $this->customer($storeId, (int) $item->getWishlistId());

        if ($customer === null) {
            return;
        }

        $product = $item->getProduct();
        $variant = $item->getOptionByCode('simple_product')?->getProduct();
        $priced = $variant instanceof Product ? $variant : $product;

        $line = new LineItem(
            (int) $product->getId(),
            (string) $product->getName(),
            (float) $priced->getPriceInfo()->getPrice('final_price')->getAmount()->getValue(),
            1,
            $variant instanceof Product ? (int) $variant->getId() : null,
            $this->catalog->categoryId($product)
        );

        $this->queue->event($storeId, $this->connection->messages($storeId)->product($event, $customer, $line, (string) $this->storeManager->getStore($storeId)->getCurrentCurrencyCode()));
    }

    private function customer(int $storeId, int $wishlistId): ?Customer
    {
        if (!$this->config->enabled('cart', $storeId) || !$this->config->server($storeId)) {
            return null;
        }

        $db = $this->resource->getConnection();
        $customerId = (int) $db->fetchOne($db->select()->from($this->resource->getTableName('wishlist'), ['customer_id'])->where('wishlist_id = ?', $wishlistId));

        if ($customerId === 0) {
            return null;
        }

        // The tracker cookie only of the owner's own browser on the storefront, not of an admin editing the wishlist.
        $own = $this->area() === Area::AREA_FRONTEND && $this->session->isLoggedIn() && (int) $this->session->getCustomerId() === $customerId;

        return new Customer((string) $customerId, $own ? $this->connection->anonymousId() : null);
    }

    private function area(): ?string
    {
        try {
            return $this->state->getAreaCode();
        } catch (\Magento\Framework\Exception\LocalizedException) {
            return null;
        }
    }
}
