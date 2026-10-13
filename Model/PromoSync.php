<?php

declare(strict_types=1);

namespace OneTrace\Connector\Model;

use Magento\Framework\FlagManager;
use Magento\SalesRule\Api\Data\RuleInterface;
use Magento\SalesRule\Api\RuleRepositoryInterface;
use Magento\SalesRule\Model\CouponFactory;
use Magento\SalesRule\Model\ResourceModel\Coupon as CouponResource;
use Magento\Store\Model\StoreManagerInterface;
use OneTrace\Exception\AuthenticationException;
use OneTrace\Exception\NotFoundException;
use Psr\Log\LoggerInterface;

/**
 * Promo codes of OneTrace pools as coupons of cart price rules (onetrace/promo/pools: "pool_key = rule id"; the rule
 * uses a specific coupon with auto generation). Every 5 minutes new and changed codes come by the cursor of the
 * platform and become coupons of the rule — one use each, the expiry of the code. The platform counts the use from the
 * coupon of the order.
 */
class PromoSync
{
    private const CURSORS = 'onetrace_promo_cursors';

    /** Coupons per pool and run; the rest come with the next run. */
    private const BATCH = 1000;

    private const PAGE = 250;

    /** Coupon type "generated" of Magento: managed by the module, not by the rule form. */
    private const GENERATED = 1;

    public function __construct(
        private readonly Config $config,
        private readonly Connection $connection,
        private readonly StoreManagerInterface $stores,
        private readonly RuleRepositoryInterface $rules,
        private readonly CouponFactory $coupons,
        private readonly CouponResource $couponResource,
        private readonly FlagManager $flags,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return array<string, int> pool key → coupons created or updated
     */
    public function run(): array
    {
        $storeId = (int) $this->stores->getDefaultStoreView()?->getId();

        if (!$this->config->server($storeId) || $this->config->promoPools() === []) {
            return [];
        }

        $cursors = $this->flags->getFlagData(self::CURSORS);
        $cursors = \is_array($cursors) ? $cursors : [];
        $done = [];

        foreach ($this->config->promoPools() as $pool => $ruleId) {
            try {
                $rule = $this->rules->getById($ruleId);
            } catch (\Throwable $error) {
                $this->logger->warning(sprintf('OneTrace: promo %s: no cart price rule %d', $pool, $ruleId));

                continue;
            }

            if ($rule->getCouponType() !== RuleInterface::COUPON_TYPE_SPECIFIC_COUPON || !$rule->getUseAutoGeneration()) {
                $this->logger->warning(sprintf('OneTrace: promo %s: rule %d needs a specific coupon with auto generation', $pool, $ruleId));

                continue;
            }

            try {
                [$done[$pool], $cursors[$pool]] = $this->sync($storeId, $pool, $ruleId, isset($cursors[$pool]) ? (string) $cursors[$pool] : null);
            } catch (AuthenticationException $error) {
                $this->logger->warning('OneTrace: promo: ' . $error->getMessage());

                break;
            } catch (NotFoundException $error) {
                $this->logger->warning(sprintf('OneTrace: promo %s: no such pool', $pool));
            } catch (\Throwable $error) {
                $this->logger->warning(sprintf('OneTrace: promo %s: %s', $pool, $error->getMessage()));
            }

            $this->flags->saveFlag(self::CURSORS, $cursors);
        }

        return $done;
    }

    /**
     * @return array{0: int, 1: string|null}
     */
    private function sync(int $storeId, string $pool, int $ruleId, ?string $cursor): array
    {
        $changed = 0;
        $client = $this->connection->client($storeId);

        do {
            $page = $client->promo()->codes($pool, $cursor, self::PAGE);
            $codes = isset($page['data']) && \is_array($page['data']) ? $page['data'] : [];

            foreach ($codes as $code) {
                if (\is_array($code) && \is_string($code['code'] ?? null) && ($code['status'] ?? '') !== 'shared') {
                    $changed += $this->coupon($ruleId, $code) ? 1 : 0;
                }
            }

            $cursor = isset($page['next']) && \is_string($page['next']) ? $page['next'] : $cursor;
        } while (\count($codes) >= self::PAGE && $changed < self::BATCH);

        return [$changed, $cursor];
    }

    /**
     * Creates the coupon of the rule or updates its expiry; a coupon of another rule with the same code is left.
     *
     * @param array<mixed> $code
     */
    private function coupon(int $ruleId, array $code): bool
    {
        $value = (string) $code['code'];
        $expires = \is_string($code['expires_at'] ?? null) && ($time = strtotime($code['expires_at'])) !== false ? gmdate('Y-m-d H:i:s', $time) : null;
        $coupon = $this->coupons->create();
        $this->couponResource->load($coupon, $value, 'code');

        if ($coupon->getId() !== null && (int) $coupon->getRuleId() !== $ruleId) {
            return false;
        }

        if ($coupon->getId() === null) {
            $coupon->setRuleId($ruleId)->setCode($value)->setUsageLimit(1)->setUsagePerCustomer(1)->setType(self::GENERATED)->setCreatedAt(gmdate('Y-m-d H:i:s'));
        }

        $coupon->setExpirationDate($expires);
        $this->couponResource->save($coupon);

        return true;
    }
}
