<?php

declare(strict_types=1);

namespace OneTrace\Connector\Model;

use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\Stdlib\CookieManagerInterface;
use Magento\Store\Model\StoreManagerInterface;
use OneTrace\Client;
use OneTrace\Commerce\Messages;

/**
 * Library clients by store view and the message builder of a store. Server messages go with the secret key only:
 * they carry consents, which the platform accepts only with it.
 */
class Connection
{
    public const VERSION = '1.1.0';

    /** @var array<string, Client> */
    private array $clients = [];

    /**
     * @param array<string, mixed> $clientOptions extra options of the client (tests pass a transport here via di.xml)
     */
    public function __construct(
        private readonly Config $config,
        private readonly StoreManagerInterface $storeManager,
        private readonly CookieManagerInterface $cookies,
        private readonly ProductMetadataInterface $productMetadata,
        private array $clientOptions = [],
    ) {
    }

    /**
     * @param string $key "secret", "write" or "search" (the write key with short timeouts: a visitor waits for it)
     */
    public function client(int $storeId, string $key = 'secret'): Client
    {
        $cacheKey = $storeId . ':' . $key;
        $write = $key === 'write' || $key === 'search';

        if (!isset($this->clients[$cacheKey])) {
            $this->clients[$cacheKey] = new Client($this->config->url($storeId), [
                $write ? 'write_key' : 'secret_key' => ($write ? $this->config->writeKey($storeId) : $this->config->secretKey($storeId)) ?: null,
                'timeout' => $key === 'search' ? 3.0 : 10.0,
                'connect_timeout' => $key === 'search' ? 2.0 : 5.0,
                // The queue retries with long pauses; the client must not hold the cron run.
                'max_retries' => 0,
                'user_agent' => sprintf('onetrace-magento2/%s Magento/%s', self::VERSION, $this->productMetadata->getVersion()),
            ] + $this->clientOptions);
        }

        return $this->clients[$cacheKey];
    }

    public function messages(int $storeId): Messages
    {
        return new Messages('magento2', (string) $this->storeManager->getStore($storeId)->getBaseUrl());
    }

    /** A new client after the settings change, and a test transport. */
    public function reset(array $clientOptions = []): void
    {
        $this->clients = [];
        $this->clientOptions = $clientOptions;
    }

    /** Value of the tracker cookie: links the request to the visitor's browser events. */
    public function anonymousId(): ?string
    {
        $value = trim((string) $this->cookies->getCookie('cdp_aid'));

        return $value !== '' && strlen($value) <= 255 && preg_match('/^[\w.:-]+$/', $value) === 1 ? $value : null;
    }
}
