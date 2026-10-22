<?php

declare(strict_types=1);

namespace OneTrace\Connector\Test\Integration;

use Magento\Customer\Api\AccountManagementInterface;
use Magento\Customer\Api\Data\CustomerInterfaceFactory;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\View\LayoutInterface;
use OneTrace\Connector\Block\Preferences\Account;
use OneTrace\Connector\Model\Preferences;
use OneTrace\Connector\Test\TestCase;
use OneTrace\Http\Request;
use OneTrace\Http\Response;

/**
 * My Account → Communication preferences: topics by channel from OneTrace, the choice sent back with unticked boxes
 * as unsubscribed, the pause, "Unsubscribe from everything" and a customer the platform does not know yet.
 */
final class PreferencesTest extends TestCase
{
    private int $status = 200;

    private string $url = '';

    private ?string $pausedUntil = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->platform->responder = function (Request $request): ?Response {
            if (preg_match('#/profiles/user_id/\d+/preferences$#', (string) parse_url($request->getUrl(), PHP_URL_PATH)) !== 1) {
                return null;
            }

            $this->url = $request->getUrl();

            return $this->status === 200 ? $this->json(200, [
                'profile_id' => 7,
                'language' => 'en-US',
                'channels' => [['key' => 'email', 'name' => 'email', 'blocked' => false], ['key' => 'sms', 'name' => 'SMS', 'blocked' => true]],
                'topics' => [
                    ['key' => 'news', 'name' => 'Store news', 'description' => 'Once a month', 'channels' => ['email' => true, 'sms' => false]],
                    ['key' => 'offers', 'name' => 'Special offers', 'description' => '', 'channels' => ['email' => false, 'sms' => null]],
                ],
                'frequency' => 'weekly',
                'paused_until' => $this->pausedUntil,
                'preferences_url' => 'https://crm.example.com/preferences/x',
                'preferences_url_expires_at' => '2026-11-21T00:00:00+00:00',
            ]) : $this->json($this->status, ['message' => 'Profile not found']);
        };
    }

    public function testTheBlockShowsTopicsByChannelTheFrequencyAndThePause(): void
    {
        $this->pausedUntil = '2026-12-01T00:00:00+00:00';
        $customer = $this->om->get(CustomerInterfaceFactory::class)->create();
        $customer->setWebsiteId(1)->setStoreId(1)->setEmail('pref-' . bin2hex(random_bytes(4)) . '@example.com')->setFirstname('Mary')->setLastname('Tester');
        $customer = $this->om->get(AccountManagementInterface::class)->createAccount($customer);
        $this->om->get(CustomerSession::class)->setCustomerId((int) $customer->getId());

        try {
            $block = $this->om->get(State::class)->emulateAreaCode(Area::AREA_FRONTEND, fn () => $this->om->get(LayoutInterface::class)->createBlock(Account::class));

            self::assertSame([['key' => 'email', 'name' => 'Email', 'blocked' => false], ['key' => 'sms', 'name' => 'SMS', 'blocked' => true]], $block->channels());
            self::assertSame(['email' => false, 'sms' => null], $block->topics()[1]['channels']);
            self::assertSame('Once a month', $block->topics()[0]['description']);
            self::assertSame('weekly', $block->frequency());
            self::assertSame(['keep', 'none', '30', '90'], array_map('strval', array_keys($block->pauses())));
            self::assertStringContainsString('language=en-US', $this->url);
        } finally {
            $this->om->get(CustomerSession::class)->setCustomerId(null);
        }
    }

    public function testSavingSendsUntickedBoxesAsUnsubscribedAndSkipsBlockedChannels(): void
    {
        $preferences = $this->om->get(Preferences::class);

        self::assertTrue($preferences->save(1, 42, 'save', ['offers' => ['email' => '1']], 'monthly', '30'));
        $body = $this->lastPut();
        self::assertSame(['news' => ['email' => false], 'offers' => ['email' => true]], $body['topics']);
        self::assertSame('monthly', $body['frequency']);
        self::assertEqualsWithDelta(time() + 30 * 86400, strtotime((string) $body['paused_until']), 60);

        self::assertTrue($preferences->save(1, 42, 'save', [], 'default', 'keep'));
        self::assertNull($this->lastPut()['frequency']);
        self::assertArrayNotHasKey('paused_until', $this->lastPut());

        self::assertTrue($preferences->save(1, 42, 'all', [], 'default', 'keep'));
        self::assertTrue($this->lastPut()['unsubscribe_all']);
    }

    public function testACustomerThePlatformDoesNotKnowYetHasNothingToChoose(): void
    {
        $preferences = $this->om->get(Preferences::class);
        $this->status = 404;
        self::assertSame(['channels' => [], 'topics' => []], $preferences->state(1, 42));

        $this->status = 403;
        self::assertNull($preferences->state(1, 42));
        self::assertFalse($preferences->save(1, 42, 'all', [], 'default', 'keep'));
    }

    /**
     * @return array<string, mixed>
     */
    private function lastPut(): array
    {
        $puts = array_values(array_filter($this->platform->requests, static fn (array $request): bool => $request['method'] === 'PUT'));

        return (array) end($puts)['body'];
    }

    /**
     * @param array<string, mixed> $body
     */
    private function json(int $status, array $body): Response
    {
        return new Response($status, ['content-type' => 'application/json'], (string) json_encode($body));
    }
}
