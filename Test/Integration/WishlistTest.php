<?php

declare(strict_types=1);

namespace OneTrace\Connector\Test\Integration;

use Magento\Customer\Api\AccountManagementInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Customer\Api\Data\CustomerInterfaceFactory;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Wishlist\Model\Wishlist;
use Magento\Wishlist\Model\WishlistFactory;
use OneTrace\Connector\Test\TestCase;

final class WishlistTest extends TestCase
{
    public function testSendsAddingAndRemovingOfTheCustomersWishlist(): void
    {
        $customer = $this->customer();
        $product = $this->product();
        $_COOKIE['cdp_aid'] = 'browser-wish';

        $item = $this->storefront($customer, fn () => $this->wishlist($customer)->addNewItem((int) $product->getId()));
        // The same product again only raises the quantity of the item.
        $this->storefront($customer, fn () => $this->wishlist($customer)->addNewItem((int) $product->getId()));
        $this->flush();

        $added = $this->platform->events('add_to_wishlist');
        self::assertCount(1, $added);
        self::assertSame((string) $customer->getId(), $added[0]['userId']);
        self::assertSame('browser-wish', $added[0]['anonymousId']);
        self::assertSame((string) $product->getId(), $added[0]['properties']['product_id']);
        self::assertSame('Sneakers', $added[0]['properties']['product_name']);
        self::assertSame(49.95, $added[0]['properties']['price']);
        self::assertArrayNotHasKey('quantity', $added[0]['properties']);

        // In the admin the cookie of the browser is not the customer's.
        $this->wishlist($customer)->getItem((int) $item->getId())->delete();
        $this->flush();

        $removed = $this->platform->events('remove_from_wishlist');
        self::assertCount(1, $removed);
        self::assertSame((string) $customer->getId(), $removed[0]['userId']);
        self::assertArrayNotHasKey('anonymousId', $removed[0]);
        self::assertSame((string) $product->getId(), $removed[0]['properties']['product_id']);

        foreach ([...$added, ...$removed] as $message) {
            self::assertContract($message);
        }
    }

    public function testMovingAnItemToTheCartRemovesItFromTheWishlist(): void
    {
        $customer = $this->customer();
        $product = $this->product();

        $this->storefront($customer, function () use ($customer, $product): void {
            $item = $this->wishlist($customer)->addNewItem((int) $product->getId());
            $item->addToCart($this->om->create(\Magento\Checkout\Model\Cart::class), true);
        });
        $this->flush();

        self::assertCount(1, $this->platform->events('add_to_wishlist'));
        self::assertCount(1, $this->platform->events('remove_from_wishlist'));
        self::assertSame((string) $product->getId(), $this->platform->events('remove_from_wishlist')[0]['properties']['product_id']);
    }

    public function testSendsNothingWithTheCartSettingOff(): void
    {
        $this->om->get(\Magento\Config\Model\ResourceModel\Config::class)->saveConfig('onetrace/parts/cart', '0');
        $this->reinit();

        try {
            $customer = $this->customer();
            $this->wishlist($customer)->addNewItem((int) $this->product()->getId());
            $this->flush();

            self::assertSame([], $this->platform->events('add_to_wishlist'));
        } finally {
            $this->om->get(\Magento\Config\Model\ResourceModel\Config::class)->deleteConfig('onetrace/parts/cart');
            $this->reinit();
        }
    }

    private function customer(): CustomerInterface
    {
        $customer = $this->om->get(CustomerInterfaceFactory::class)->create();
        $customer->setWebsiteId(1)->setStoreId(1)->setEmail('wish-' . bin2hex(random_bytes(4)) . '@example.com')->setFirstname('Mary')->setLastname('Tester');

        return $this->om->get(AccountManagementInterface::class)->createAccount($customer);
    }

    private function wishlist(CustomerInterface $customer): Wishlist
    {
        return $this->om->create(WishlistFactory::class)->create()->loadByCustomerId((int) $customer->getId(), true);
    }

    /**
     * On the storefront, signed in as the customer.
     */
    private function storefront(CustomerInterface $customer, callable $callback): mixed
    {
        $session = $this->om->get(CustomerSession::class);
        $session->setCustomerId((int) $customer->getId());

        try {
            return $this->om->get(State::class)->emulateAreaCode(Area::AREA_FRONTEND, $callback);
        } finally {
            $session->setCustomerId(null);
        }
    }
}
