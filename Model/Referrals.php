<?php

declare(strict_types=1);

namespace OneTrace\Connector\Model;

use Magento\Framework\FlagManager;
use Magento\Framework\Stdlib\CookieManagerInterface;
use OneTrace\Exception\NotFoundException;
use OneTrace\Exception\ValidationException;
use OneTrace\Identity;
use Psr\Log\LoggerInterface;

/**
 * Referral program of OneTrace: on registration the friend's code — from the "Friend's code" field (setting
 * onetrace/parts/referral_field) or from the cdp_ref cookie of the tracker (a ?ref= link) — waits for the identify of
 * the new customer and goes to the platform from cron; while the platform does not know the profile (404) it is
 * repeated up to 5 times.
 */
class Referrals
{
    public const FIELD = 'onetrace_referral_code';

    private const COOKIE = 'cdp_ref';

    private const FLAG = 'onetrace_referrals';

    private const ATTEMPTS = 5;

    public function __construct(
        private readonly Config $config,
        private readonly Connection $connection,
        private readonly CookieManagerInterface $cookies,
        private readonly FlagManager $flags,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * The code of the registration: the field (when shown) or the cookie; null — none or invalid.
     */
    public function code(?string $typed): ?string
    {
        return self::normalize($this->config->enabled('referral_field') ? (string) $typed : '') ?? self::normalize((string) $this->cookies->getCookie(self::COOKIE));
    }

    public function add(int $storeId, int $customerId, string $code): void
    {
        $pending = $this->pending();
        $pending[] = ['store' => $storeId, 'customer' => $customerId, 'code' => $code, 'attempt' => 1, 'at' => time() + 30];
        $this->flags->saveFlag(self::FLAG, $pending);
    }

    /**
     * Sends the codes that are due.
     *
     * @return int sent
     */
    public function process(): int
    {
        $pending = $this->pending();
        $left = [];
        $sent = 0;

        foreach ($pending as $item) {
            if ($item['at'] > time()) {
                $left[] = $item;

                continue;
            }

            try {
                $this->connection->client($item['store'])->loyalty()->refer(Identity::userId((string) $item['customer']), $item['code'], 'm2-referral-' . $item['customer'] . '-' . $item['attempt']);
                $sent++;
            } catch (ValidationException $error) {
                // Own code, an existing customer, referrals off — nothing to repeat.
                $this->logger->info(sprintf('OneTrace: referral %d: %s', $item['customer'], $error->getMessage()));
            } catch (\Throwable $error) {
                if (!$error instanceof NotFoundException) {
                    $this->logger->warning(sprintf('OneTrace: referral %d: %s', $item['customer'], $error->getMessage()));
                }

                if ($item['attempt'] < self::ATTEMPTS) {
                    $left[] = ['attempt' => $item['attempt'] + 1, 'at' => time() + 60 * $item['attempt']] + $item;
                }
            }
        }

        $this->flags->saveFlag(self::FLAG, $left);

        return $sent;
    }

    /**
     * @return list<array{store: int, customer: int, code: string, attempt: int, at: int}>
     */
    public function pending(): array
    {
        $data = $this->flags->getFlagData(self::FLAG);
        $items = [];

        foreach (\is_array($data) ? $data : [] as $item) {
            if (\is_array($item) && isset($item['store'], $item['customer'], $item['code'], $item['attempt'], $item['at'])) {
                $items[] = ['store' => (int) $item['store'], 'customer' => (int) $item['customer'], 'code' => (string) $item['code'], 'attempt' => (int) $item['attempt'], 'at' => (int) $item['at']];
            }
        }

        return $items;
    }

    private static function normalize(string $code): ?string
    {
        $code = strtoupper(trim($code));

        return preg_match('/^[A-Z0-9]{4,16}$/', $code) === 1 ? $code : null;
    }
}
