<?php

declare(strict_types=1);

namespace OneTrace\Connector\Model;

use Magento\Customer\Model\Url as CustomerUrl;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Framework\UrlInterface;
use Magento\Quote\Model\Quote;

/**
 * What the bonus points block of the cart and the checkout shows, with the texts in the store language: a hint to
 * sign in, the join link, the balance with the points field or the applied points; null — nothing to show.
 */
class LoyaltyState
{
    public function __construct(
        private readonly Loyalty $loyalty,
        private readonly PriceCurrencyInterface $prices,
        private readonly CustomerUrl $customerUrl,
        private readonly UrlInterface $urls,
    ) {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function build(Quote $quote): ?array
    {
        if (!$this->loyalty->enabled((int) $quote->getStoreId()) || !$quote->hasItems()) {
            return null;
        }

        $title = (string) __('Bonus points');

        if ($this->loyalty->profile($quote) === null) {
            return ['title' => $title, 'link' => ['url' => $this->customerUrl->getLoginUrl(), 'text' => (string) __('Sign in to use your bonus points')]];
        }

        $offer = $this->loyalty->offer($quote);

        if ($offer === null) {
            return null;
        }

        if (empty($offer['member'])) {
            return ['title' => $title, 'link' => ['url' => $this->urls->getUrl('onetrace/loyalty'), 'text' => (string) __('Join the loyalty program to collect points')]];
        }

        $earnPoints = $this->loyalty->earn($quote, $offer);
        $earn = $earnPoints > 0 ? (string) __('Points for this order: +%1', $earnPoints) : null;
        $points = (int) $quote->getData('onetrace_loyalty_points');

        if ($points > 0) {
            return [
                'title' => $title,
                'applied' => (string) __('Points used: %1 (−%2)', $points, $this->money((float) $quote->getData('onetrace_loyalty_discount'))),
                'remove' => (string) __('Remove'),
                'earn' => $earn,
            ];
        }

        $balance = (int) ($offer['balance'] ?? 0);
        $max = (int) ($offer['max_points'] ?? 0);

        return [
            'title' => $title,
            'balance' => (string) __('Your points: %1 (%2).', $balance, $this->money($balance * (float) ($offer['point_value'] ?? 1))),
            'max' => $max,
            'hint' => $max > 0 ? (string) __('This cart can take up to %1 points (−%2).', $max, $this->money((float) ($offer['max_discount'] ?? 0))) : null,
            'field' => (string) __('Points to use'),
            'apply' => (string) __('Use points'),
            'earn' => $earn,
        ];
    }

    private function money(float $amount): string
    {
        return html_entity_decode(strip_tags((string) $this->prices->format($amount, false)), ENT_QUOTES, 'UTF-8');
    }
}
