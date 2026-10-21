<?php

declare(strict_types=1);

namespace OneTrace\Connector\Model\StoreMcp;

use Magento\Framework\App\CacheInterface;
use OneTrace\StoreMcp\CodeStore;

/**
 * Confirmation codes and their limits in the Magento cache (Redis or files): a flushed cache only means the customer
 * asks for a new code.
 */
class CacheCodeStore implements CodeStore
{
    private const TAG = 'ONETRACE_STORE_MCP';

    public function __construct(private readonly CacheInterface $cache)
    {
    }

    public function get(string $key): ?array
    {
        $value = $this->cache->load($this->id($key));
        $data = is_string($value) ? json_decode($value, true) : null;

        return is_array($data) ? $data : null;
    }

    public function put(string $key, array $value, int $ttl): void
    {
        $this->cache->save((string) json_encode($value), $this->id($key), [self::TAG], $ttl);
    }

    public function delete(string $key): void
    {
        $this->cache->remove($this->id($key));
    }

    private function id(string $key): string
    {
        return 'onetrace_mcp_' . sha1($key);
    }
}
