<?php

declare(strict_types=1);

namespace OneTrace\Connector\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\FlagManager;
use OneTrace\Commerce\Retry;
use Psr\Log\LoggerInterface;

/**
 * Outgoing queue: messages and catalog changes wait in onetrace_queue and are sent in batches by cron every minute,
 * so the store never waits for the platform and nothing is lost while it is unavailable.
 *
 * Kinds: "event" — a message for POST /batch; "product" — a product id to upload (read fresh when sent);
 * "delete" — a product id to remove from the catalog.
 */
class Queue
{
    public const EVENT = 'event';

    public const PRODUCT = 'product';

    public const DELETE = 'delete';

    /** Messages per request to the platform. */
    public const BATCH = 100;

    /** Products per request: building items loads products, so the batch is smaller. */
    public const PRODUCT_BATCH = 50;

    private const TABLE = 'onetrace_queue';

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly Config $config,
        private readonly Connection $connection,
        private readonly Catalog $catalog,
        private readonly FlagManager $flags,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, mixed>|null $message
     */
    public function event(int $storeId, ?array $message): void
    {
        if ($message !== null && $this->config->server($storeId)) {
            $this->push($storeId, self::EVENT, '', (string) json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
        }
    }

    /**
     * A product to upload to every project the catalog goes to; one waiting item per product and store view.
     */
    public function product(int $productId): void
    {
        foreach ($this->config->catalogStores() as $storeId) {
            $this->push($storeId, self::PRODUCT, 'p' . $productId, (string) $productId, true);
        }
    }

    public function delete(int $productId): void
    {
        foreach ($this->config->catalogStores() as $storeId) {
            $this->push($storeId, self::DELETE, 'p' . $productId, (string) $productId, true);
        }
    }

    /**
     * Sends what is due.
     *
     * @return array{sent: int, retried: int, dropped: int}
     */
    public function flush(int $limit = 5000): array
    {
        $result = ['sent' => 0, 'retried' => 0, 'dropped' => 0];
        $db = $this->resource->getConnection();
        $stores = $db->fetchCol($db->select()->distinct()->from($this->resource->getTableName(self::TABLE), ['store_id']));

        foreach (array_map('intval', $stores) as $storeId) {
            foreach ([self::EVENT, self::PRODUCT, self::DELETE] as $kind) {
                while ($limit > 0 && !$this->paused($storeId) && $this->config->server($storeId)
                    && ($rows = $this->due($storeId, $kind, min($limit, $kind === self::PRODUCT ? self::PRODUCT_BATCH : self::BATCH))) !== []) {
                    $limit -= count($rows);

                    foreach ($this->send($storeId, $kind, $rows) as $key => $count) {
                        $result[$key] += $count;
                    }
                }
            }
        }

        return $result;
    }

    /**
     * @return array{waiting: int, retrying: int}
     */
    public function stats(): array
    {
        $db = $this->resource->getConnection();
        $row = $db->fetchRow($db->select()->from($this->resource->getTableName(self::TABLE), ['waiting' => 'COUNT(*)', 'retrying' => 'SUM(attempts > 0)']));

        return ['waiting' => (int) ($row['waiting'] ?? 0), 'retrying' => (int) ($row['retrying'] ?? 0)];
    }

    public function lastError(): ?string
    {
        $db = $this->resource->getConnection();
        $error = $db->fetchOne($db->select()->from($this->resource->getTableName(self::TABLE), ['last_error'])->where('last_error IS NOT NULL')->order('id DESC')->limit(1));

        return is_string($error) && $error !== '' ? $error : null;
    }

    /** Sending of a store view stops until its settings are saved again: the platform rejected the key. */
    public function paused(int $storeId): bool
    {
        return is_string($this->flags->getFlagData('onetrace_paused_' . $storeId));
    }

    public function pausedReason(int $storeId): ?string
    {
        $reason = $this->flags->getFlagData('onetrace_paused_' . $storeId);

        return is_string($reason) ? $reason : null;
    }

    public function resume(?int $storeId = null): void
    {
        $db = $this->resource->getConnection();
        $flags = $db->fetchCol($db->select()->from($this->resource->getTableName('flag'), ['flag_code'])->where('flag_code LIKE ?', 'onetrace_paused_%'));

        foreach ($flags as $code) {
            if ($storeId === null || $code === 'onetrace_paused_' . $storeId) {
                $this->flags->deleteFlag((string) $code);
            }
        }
    }

    private function push(int $storeId, string $kind, string $key, string $payload, bool $unique = false): void
    {
        $db = $this->resource->getConnection();
        $table = $this->resource->getTableName(self::TABLE);
        $now = gmdate('Y-m-d H:i:s');

        if ($unique) {
            // A newer change of the same product replaces the waiting one (upload ⇄ delete).
            $db->delete($table, ['store_id = ?' => $storeId, 'item_key = ?' => $key, 'attempts = ?' => 0]);
        }

        $db->insert($table, ['store_id' => $storeId, 'kind' => $kind, 'item_key' => $key, 'payload' => $payload, 'available_at' => $now, 'created_at' => $now]);
    }

    /**
     * @return list<array{id: string, payload: string, attempts: string}>
     */
    private function due(int $storeId, string $kind, int $limit): array
    {
        $db = $this->resource->getConnection();

        /** @var list<array{id: string, payload: string, attempts: string}> */
        return $db->fetchAll($db->select()
            ->from($this->resource->getTableName(self::TABLE), ['id', 'payload', 'attempts'])
            ->where('store_id = ?', $storeId)
            ->where('kind = ?', $kind)
            ->where('available_at <= ?', gmdate('Y-m-d H:i:s'))
            ->order('id')
            ->limit($limit));
    }

    /**
     * @param list<array{id: string, payload: string, attempts: string}> $rows
     *
     * @return array{sent: int, retried: int, dropped: int}
     */
    private function send(int $storeId, string $kind, array $rows): array
    {
        try {
            $client = $this->connection->client($storeId);

            if ($kind === self::EVENT) {
                $client->events()->batch(array_map(static fn (array $row): array => (array) json_decode($row['payload'], true), $rows));
            } elseif ($kind === self::PRODUCT) {
                [$items, $categories, $missing] = $this->catalog->items($storeId, array_map('intval', array_column($rows, 'payload')));

                if ($items !== []) {
                    $client->products()->upsert($items, $categories);
                }

                if ($missing !== []) {
                    $client->products()->delete($missing);
                }
            } else {
                $client->products()->delete(array_column($rows, 'payload'));
            }
        } catch (\Throwable $error) {
            return $this->failed($storeId, $kind, $rows, $error);
        }

        $this->remove(array_column($rows, 'id'));

        return ['sent' => count($rows), 'retried' => 0, 'dropped' => 0];
    }

    /**
     * @param list<array{id: string, payload: string, attempts: string}> $rows
     *
     * @return array{sent: int, retried: int, dropped: int}
     */
    private function failed(int $storeId, string $kind, array $rows, \Throwable $error): array
    {
        // Errors outside the platform's answers (database, a bug while building items) are retried, not dropped.
        $decision = $error instanceof \OneTrace\Exception\OneTraceException ? Retry::decide($error) : Retry::RETRY;
        $message = mb_substr(get_class($error) . ': ' . $error->getMessage(), 0, 1000);

        if ($decision === Retry::SETTINGS) {
            $this->flags->saveFlag('onetrace_paused_' . $storeId, $message);
            $this->logger->warning('OneTrace: sending paused for store ' . $storeId . ': ' . $message);

            return ['sent' => 0, 'retried' => 0, 'dropped' => 0];
        }

        // Invalid data in a batch: send the messages one by one to drop only the broken ones.
        if ($decision === Retry::DROP && count($rows) > 1) {
            $result = ['sent' => 0, 'retried' => 0, 'dropped' => 0];

            foreach ($rows as $row) {
                foreach ($this->send($storeId, $kind, [$row]) as $key => $count) {
                    $result[$key] += $count;
                }
            }

            return $result;
        }

        if ($decision === Retry::DROP) {
            $this->remove(array_column($rows, 'id'));
            $this->logger->warning(sprintf('OneTrace: dropped %s %s: %s', $kind, $rows[0]['payload'], $message));

            return ['sent' => 0, 'retried' => 0, 'dropped' => count($rows)];
        }

        $db = $this->resource->getConnection();
        $retried = 0;
        $dropped = [];

        foreach ($rows as $row) {
            $attempt = (int) $row['attempts'] + 1;
            $delay = Retry::delay($attempt);

            if ($delay === null) {
                $dropped[] = $row['id'];

                continue;
            }

            $db->update($this->resource->getTableName(self::TABLE), ['attempts' => $attempt, 'available_at' => gmdate('Y-m-d H:i:s', time() + $delay), 'last_error' => $message], ['id = ?' => $row['id']]);
            ++$retried;
        }

        if ($dropped !== []) {
            $this->remove($dropped);
            $this->logger->warning(sprintf('OneTrace: dropped %d %s item(s) after all retries: %s', count($dropped), $kind, $message));
        }

        return ['sent' => 0, 'retried' => $retried, 'dropped' => count($dropped)];
    }

    /**
     * @param array<string> $ids
     */
    private function remove(array $ids): void
    {
        if ($ids !== []) {
            $this->resource->getConnection()->delete($this->resource->getTableName(self::TABLE), ['id IN (?)' => array_map('intval', $ids)]);
        }
    }
}
