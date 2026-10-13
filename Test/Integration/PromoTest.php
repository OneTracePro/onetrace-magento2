<?php

declare(strict_types=1);

namespace OneTrace\Connector\Test\Integration;

use Magento\Framework\FlagManager;
use Magento\SalesRule\Model\Coupon;
use Magento\SalesRule\Model\ResourceModel\Coupon as CouponResource;
use Magento\SalesRule\Model\Rule;
use Magento\SalesRule\Model\RuleFactory;
use OneTrace\Connector\Model\PromoSync;
use OneTrace\Connector\Model\Referrals;
use OneTrace\Connector\Test\TestCase;
use OneTrace\Http\Request;
use OneTrace\Http\Response;

/**
 * Promo codes and referrals: codes of a pool become coupons of the bound cart price rule (one use, the expiry of the
 * code, by the cursor, coupons of other rules untouched); the friend's code of the registration waits for cron and is
 * repeated while the platform does not know the profile.
 */
final class PromoTest extends TestCase
{
    protected const SETTINGS = parent::SETTINGS + ['onetrace/parts/loyalty' => 1, 'onetrace/parts/referral_field' => 1];

    /** @var list<array<string, mixed>> */
    private array $codes = [];

    private int $referStatus = 200;

    private int $ruleId;

    private int $otherRuleId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ruleId = $this->rule('OneTrace welcome');
        $this->otherRuleId = $this->rule('Store own');
        $this->om->get(\Magento\Config\Model\ResourceModel\Config::class)->saveConfig('onetrace/promo/pools', "welcome = {$this->ruleId}\nmissing = 999999");
        $this->om->get(\Magento\Framework\App\Config\ReinitableConfigInterface::class)->reinit();
        $this->om->get(\Magento\Framework\App\Cache\TypeListInterface::class)->cleanType('config');
        $flags = $this->om->get(FlagManager::class);
        $flags->deleteFlag('onetrace_promo_cursors');
        $flags->deleteFlag('onetrace_referrals');

        foreach (['WEL-AAAA', 'WEL-BBBB', 'STORE-OWN'] as $code) {
            $coupon = $this->coupon($code);

            if ($coupon->getId() !== null) {
                $this->om->get(CouponResource::class)->delete($coupon);
            }
        }

        $own = $this->om->create(Coupon::class);
        $own->setRuleId($this->otherRuleId)->setCode('STORE-OWN')->setUsageLimit(10)->setType(1);
        $this->om->get(CouponResource::class)->save($own);

        $this->platform->responder = function (Request $request): ?Response {
            $path = (string) parse_url($request->getUrl(), PHP_URL_PATH);
            parse_str((string) parse_url($request->getUrl(), PHP_URL_QUERY), $query);

            if ($path === '/api/v1/promo/pools/welcome/codes') {
                return $this->json(200, ['data' => ($query['since'] ?? '') === 'c2' ? [] : $this->codes, 'next' => 'c2']);
            }

            if ($path === '/api/v1/loyalty/referrals') {
                return $this->json($this->referStatus, $this->referStatus === 422 ? ['message' => 'Own code', 'errors' => ['code' => ['Own code']]] : ($this->referStatus === 404 ? ['message' => 'Not found'] : ['status' => 'pending', 'rewarded' => true]));
            }

            return null;
        };
    }

    public function testCodesOfAPoolBecomeCouponsOfTheBoundRule(): void
    {
        $this->codes = [
            ['code' => 'WEL-AAAA', 'status' => 'available', 'expires_at' => null],
            ['code' => 'WEL-BBBB', 'status' => 'issued', 'expires_at' => '2026-11-30T00:00:00Z'],
            ['code' => 'STORE-OWN', 'status' => 'available', 'expires_at' => null],
        ];

        self::assertSame(['welcome' => 2], $this->om->get(PromoSync::class)->run());

        $coupon = $this->coupon('WEL-BBBB');
        self::assertSame([$this->ruleId, 1, 1], [(int) $coupon->getRuleId(), (int) $coupon->getUsageLimit(), (int) $coupon->getUsagePerCustomer()]);
        self::assertStringStartsWith('2026-11-30', (string) $coupon->getExpirationDate());
        self::assertSame($this->otherRuleId, (int) $this->coupon('STORE-OWN')->getRuleId(), 'A coupon of another rule is left as it is.');
        self::assertSame(['welcome' => 'c2'], $this->om->get(FlagManager::class)->getFlagData('onetrace_promo_cursors'));
        self::assertSame(['welcome' => 0], $this->om->get(PromoSync::class)->run());
    }

    public function testTheFriendsCodeOfTheRegistrationIsSentFromCronAndRepeatedWhileTheProfileIsUnknown(): void
    {
        $referrals = $this->om->get(Referrals::class);
        self::assertSame('K7M2QX9A', $referrals->code(' k7m2qx9a '));

        $referrals->add(1, 77, 'K7M2QX9A');
        self::assertSame(0, $referrals->process(), 'Not due yet: the identify of the customer goes first.');

        $this->due();
        $this->referStatus = 404;
        self::assertSame(0, $referrals->process());
        self::assertSame(['profile' => ['type' => 'user_id', 'value' => '77'], 'code' => 'K7M2QX9A'], $this->lastRequest('/api/v1/loyalty/referrals')['body']);
        self::assertSame(2, $referrals->pending()[0]['attempt']);

        $this->due();
        $this->referStatus = 200;
        self::assertSame(1, $referrals->process());
        self::assertSame([], $referrals->pending());

        $referrals->add(1, 78, 'K7M2QX9A');
        $this->due();
        $this->referStatus = 422;
        $referrals->process();
        self::assertSame([], $referrals->pending(), 'An own code is not repeated.');
    }

    private function due(): void
    {
        $flags = $this->om->get(FlagManager::class);
        $flags->saveFlag('onetrace_referrals', array_map(static function (array $item): array {
            return ['at' => 0] + $item;
        }, $this->om->get(Referrals::class)->pending()));
    }

    private function rule(string $name): int
    {
        /** @var Rule $rule */
        $rule = $this->om->get(RuleFactory::class)->create();
        $rule->setName($name)->setIsActive(1)->setWebsiteIds([1])->setCustomerGroupIds([0, 1])
            ->setCouponType(Rule::COUPON_TYPE_SPECIFIC)->setUseAutoGeneration(1)->setSimpleAction('by_percent')->setDiscountAmount(10);
        $rule->save();

        return (int) $rule->getId();
    }

    private function coupon(string $code): Coupon
    {
        $coupon = $this->om->create(Coupon::class);
        $this->om->get(CouponResource::class)->load($coupon, $code, 'code');

        return $coupon;
    }

    /**
     * @return array{method: string, path: string, body: mixed}
     */
    private function lastRequest(string $path): array
    {
        $found = array_values(array_filter($this->platform->requests, static function (array $request) use ($path): bool {
            return $request['path'] === $path;
        }));

        return $found[\count($found) - 1];
    }

    /**
     * @param array<string, mixed> $body
     */
    private function json(int $status, array $body): Response
    {
        return new Response($status, ['content-type' => 'application/json'], (string) json_encode($body));
    }
}
