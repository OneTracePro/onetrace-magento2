<?php

declare(strict_types=1);

namespace OneTrace\Connector\Model\Email;

use Magento\Framework\App\CacheInterface;
use OneTrace\Connector\Model\Config;
use OneTrace\Connector\Model\Connection;
use Psr\Log\LoggerInterface;

/**
 * Store email settings of the project (the store emails section of the platform): relay all store emails as is
 * and the order emails built from OneTrace templates. Read with the secret key and kept in the Magento cache for
 * LIFETIME seconds (also a failure: a key without messages.send or an unavailable platform does not slow every
 * email down); saving the module settings cleans it.
 */
class Settings
{
    public const LIFETIME = 300;

    public const CACHE_TAG = 'ONETRACE_EMAILS';

    /** @var array<string, array{relay: bool, integration_id: int|null, templates: array<string, bool>}|null> */
    private array $loaded = [];

    public function __construct(
        private readonly Config $config,
        private readonly Connection $connection,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Settings for the project of a store view; null — store emails are not available (no secret key, the key has
     * no messages.send permission, the platform did not answer): Magento sends its emails itself.
     *
     * @return array{relay: bool, integration_id: int|null, templates: array<string, bool>}|null
     */
    public function get(int $storeId): ?array
    {
        if (!$this->config->server($storeId)) {
            return null;
        }

        $key = 'onetrace_emails_' . sha1($this->config->url($storeId) . '|' . $this->config->secretKey($storeId));

        if (array_key_exists($key, $this->loaded)) {
            return $this->loaded[$key];
        }

        $cached = $this->cache->load($key);

        if (is_string($cached) && $cached !== '') {
            $value = json_decode($cached, true);

            if (is_array($value)) {
                return $this->loaded[$key] = isset($value['relay']) ? self::shape($value) : null;
            }
        }

        try {
            $settings = $this->connection->client($storeId, 'email')->emails()->settings();
        } catch (\Throwable $error) {
            $this->logger->warning('OneTrace: store email settings: ' . $error->getMessage());
            $settings = null;
        }

        $this->cache->save((string) json_encode($settings ?? ['failed' => true]), $key, [self::CACHE_TAG], self::LIFETIME);

        return $this->loaded[$key] = $settings;
    }

    /** True if any store email of the project goes through OneTrace. */
    public static function active(?array $settings): bool
    {
        return $settings !== null && ($settings['relay'] || in_array(true, $settings['templates'], true));
    }

    public function clean(): void
    {
        $this->loaded = [];
        $this->cache->clean([self::CACHE_TAG]);
    }

    /**
     * @param array<mixed> $value
     *
     * @return array{relay: bool, integration_id: int|null, templates: array<string, bool>}
     */
    private static function shape(array $value): array
    {
        return [
            'relay' => ($value['relay'] ?? false) === true,
            'integration_id' => is_int($value['integration_id'] ?? null) ? $value['integration_id'] : null,
            'templates' => array_map('boolval', (array) ($value['templates'] ?? [])),
        ];
    }
}
