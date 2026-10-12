<?php

declare(strict_types=1);

namespace OneTrace\Connector\Model;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use Magento\Sales\Model\Order;
use OneTrace\Exception\ApiException;
use OneTrace\Identity;
use Psr\Log\LoggerInterface;

/**
 * Bonus points of the OneTrace loyalty program at checkout: the offer for the cart (balance, how many points the cart
 * can take, points for the order), a hold of the chosen points for 30 minutes stored with the quote, the discount as
 * the onetrace_loyalty total and the confirmation with the order number. Only signed-in customers redeem points: the
 * platform finds them by user_id (the customer id), like the order events. Cancelled and refunded orders return the
 * points on the platform side.
 */
class Loyalty
{
    private const OFFER_TTL = 60;

    public function __construct(
        private readonly Config $config,
        private readonly Connection $connection,
        private readonly CartRepositoryInterface $quotes,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function enabled(int $storeId): bool
    {
        return $this->config->enabled('loyalty', $storeId) && $this->config->server($storeId);
    }

    /**
     * @return array{type: string, value: string}|null
     */
    public function profile(Quote $quote): ?array
    {
        $customerId = (int) $quote->getCustomerId();

        return $customerId > 0 && !$quote->getCustomerIsGuest() ? Identity::userId((string) $customerId) : null;
    }

    /**
     * Amount the points may pay: goods after discounts with taxes, without shipping.
     */
    public function basis(Quote $quote): float
    {
        $address = $quote->isVirtual() ? $quote->getBillingAddress() : $quote->getShippingAddress();

        return max(0.0, round((float) $address->getSubtotalInclTax() + (float) $address->getDiscountAmount(), 2));
    }

    /**
     * @return array<string, mixed>|null member, balance, max_points, max_discount, earn_points, point_value, currency, tier
     */
    public function offer(Quote $quote): ?array
    {
        $profile = $this->profile($quote);
        $basis = $this->basis($quote);

        if ($profile === null || $basis <= 0) {
            return null;
        }

        $key = $this->offerKey($profile['value'], $basis);
        $cached = $this->cache->load($key);

        if (is_string($cached) && is_array($offer = json_decode($cached, true))) {
            return $offer;
        }

        try {
            $offer = $this->connection->client((int) $quote->getStoreId())->loyalty()->calculate($profile, $basis);
        } catch (\Throwable $error) {
            $this->logger->warning('OneTrace: loyalty offer: ' . $error->getMessage());

            return null;
        }

        $this->cache->save((string) json_encode($offer), $key, [], self::OFFER_TTL);

        return $offer;
    }

    /**
     * Holds the points for the cart (a new hold of the same cart replaces the previous one) and recalculates the totals.
     *
     * @throws LocalizedException the platform refused: more than the balance or the allowed share of the cart
     */
    public function apply(Quote $quote, int $points): void
    {
        $profile = $this->profile($quote);

        if ($profile === null || $points <= 0) {
            throw new LocalizedException(__('Sign in to use your bonus points.'));
        }

        $basis = $this->basis($quote);
        $offer = $this->offer($quote);

        try {
            $redemption = $this->connection->client((int) $quote->getStoreId())->loyalty()->hold($profile, $points, $this->cartId($quote), $basis);
        } catch (\Throwable $error) {
            throw new LocalizedException(__('Could not use the points: %1', $error->getMessage()));
        }

        $this->cache->remove($this->offerKey($profile['value'], $basis));
        $discount = (float) $redemption['discount'];
        $rate = (float) $quote->getBaseToQuoteRate() ?: 1.0;
        $quote->addData([
            'onetrace_loyalty_points' => (int) $redemption['points'],
            'onetrace_loyalty_discount' => $discount,
            'base_onetrace_loyalty_discount' => round($discount / $rate, 4),
            'onetrace_loyalty_redemption' => (string) $redemption['id'],
            'onetrace_loyalty_basis' => $basis,
            'onetrace_loyalty_ratio' => $basis > 0 && $offer !== null ? round((float) ($offer['max_discount'] ?? 0) / $basis, 4) : 0,
            'onetrace_loyalty_held_until' => (int) strtotime((string) $redemption['held_until']),
        ]);
        $this->save($quote);
    }

    /**
     * Removes the points from the cart and returns them to the balance.
     */
    public function remove(Quote $quote): void
    {
        $id = (string) $quote->getData('onetrace_loyalty_redemption');
        $profile = $this->profile($quote);
        $this->clear($quote);

        if ($profile !== null) {
            $this->cache->remove($this->offerKey($profile['value'], $this->basis($quote)));
        }

        $this->save($quote);

        if ($id !== '') {
            try {
                $this->connection->client((int) $quote->getStoreId())->loyalty()->cancel($id);
            } catch (\Throwable $error) {
                // The hold expires by itself in 30 minutes.
            }
        }
    }

    /**
     * Before the order: the hold must be alive and match the cart. A changed cart or a hold about to expire is held
     * again within the allowed share; if the platform refuses, the points are removed and the customer sees why.
     *
     * @throws LocalizedException
     */
    public function prepare(Quote $quote): void
    {
        $points = (int) $quote->getData('onetrace_loyalty_points');

        if ($points <= 0) {
            return;
        }

        $basis = $this->basis($quote);
        $stale = abs($basis - (float) $quote->getData('onetrace_loyalty_basis')) >= 0.01 || (int) $quote->getData('onetrace_loyalty_held_until') - time() < 120;

        if (!$stale) {
            return;
        }

        $amount = (float) $quote->getData('onetrace_loyalty_discount');
        $ratio = (float) $quote->getData('onetrace_loyalty_ratio');
        $perPoint = $amount / $points;
        $allowed = $ratio > 0 && $perPoint > 0 ? (int) floor($basis * $ratio / $perPoint + 1e-9) : $points;

        try {
            $this->apply($quote, min($points, $allowed));
        } catch (LocalizedException $error) {
            $this->remove($quote);

            throw new LocalizedException(__('The bonus points could not be held for this cart, they were removed. Please review the total and place the order again.'));
        }
    }

    /**
     * Confirms the redemption with the order number; false — the platform did not answer (cron retries).
     */
    public function confirm(Order $order): bool
    {
        $id = (string) $order->getData('onetrace_loyalty_redemption');

        if ($id === '' || $order->getData('onetrace_loyalty_confirmed_at')) {
            return true;
        }

        try {
            $this->connection->client((int) $order->getStoreId())->loyalty()->confirm($id, (string) $order->getIncrementId());
        } catch (\Throwable $error) {
            $this->logger->warning('OneTrace: loyalty confirmation of order ' . $order->getIncrementId() . ': ' . $error->getMessage());

            // 4xx (the hold expired or was confirmed by another order) — retrying will not help.
            if (!$error instanceof ApiException || $error->getCode() >= 500 || $error->getCode() === 429) {
                return false;
            }
        }

        $order->setData('onetrace_loyalty_confirmed_at', gmdate('Y-m-d H:i:s'));
        $order->getResource()->saveAttribute($order, 'onetrace_loyalty_confirmed_at');

        return true;
    }

    /**
     * Points for the order: the offer counts them for the cart paid with the most points; with fewer points held
     * the paid amount is larger — the points grow in proportion.
     *
     * @param array<string, mixed> $offer
     */
    public function earn(Quote $quote, array $offer): int
    {
        $earn = (int) ($offer['earn_points'] ?? 0);
        $basis = $this->basis($quote);
        $paidAtMax = $basis - (float) ($offer['max_discount'] ?? 0);
        $held = (float) $quote->getData('onetrace_loyalty_discount');

        if ($held <= 0 || $earn <= 0 || $paidAtMax <= 0) {
            return $earn;
        }

        return (int) floor($earn * ($basis - min($held, $basis)) / $paidAtMax);
    }

    public function clear(Quote $quote): void
    {
        $quote->addData([
            'onetrace_loyalty_points' => 0,
            'onetrace_loyalty_discount' => 0,
            'base_onetrace_loyalty_discount' => 0,
            'onetrace_loyalty_redemption' => null,
            'onetrace_loyalty_basis' => 0,
            'onetrace_loyalty_ratio' => 0,
            'onetrace_loyalty_held_until' => 0,
        ]);
    }

    private function save(Quote $quote): void
    {
        $quote->setTotalsCollectedFlag(false)->collectTotals();
        $this->quotes->save($quote);
    }

    private function cartId(Quote $quote): string
    {
        return 'm2:' . $quote->getId();
    }

    private function offerKey(string $user, float $basis): string
    {
        return 'onetrace_loyalty_offer_' . hash('sha256', $user . '|' . $basis);
    }
}
