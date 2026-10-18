<?php

declare(strict_types=1);

namespace OneTrace\Connector\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use OneTrace\Connector\Model\Wishlist;
use Psr\Log\LoggerInterface;

/**
 * Items of Magento's wishlist: a new item sends add_to_wishlist (wishlist_item_save_after), a deleted one —
 * remove_from_wishlist (wishlist_item_delete_after: removing, a quantity of 0, moving to the cart, GraphQL).
 */
class WishlistChanged implements ObserverInterface
{
    public function __construct(private readonly Wishlist $wishlist, private readonly LoggerInterface $logger)
    {
    }

    public function execute(Observer $observer): void
    {
        $item = $observer->getEvent()->getData('item');

        if (!$item instanceof \Magento\Wishlist\Model\Item) {
            return;
        }

        try {
            if ($observer->getEvent()->getName() === 'wishlist_item_delete_after') {
                $this->wishlist->removed($item);
            } elseif ($item->isObjectNew()) {
                $this->wishlist->added($item);
            }
        } catch (\Throwable $error) {
            $this->logger->error('OneTrace: wishlist: ' . $error->getMessage());
        }
    }
}
