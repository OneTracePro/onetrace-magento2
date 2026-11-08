<?php

declare(strict_types=1);

namespace OneTrace\Connector\Model\StoreMcp;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\DuplicateException;

/**
 * Orders placed through the store MCP (contract v2): the idempotency key of each order, where it came from (the chat
 * bot or an AI shopping assistant) and which assistant, in the table onetrace_assistant_order. The key is reserved
 * before the order is placed and the quote is written to it right before the submit, so a repeat finds the order
 * even when the cache was flushed or the request died after Magento had placed it.
 */
class AssistantOrders
{
    /** A reservation without an order and without a placed quote this old is a request that died: taken again. */
    private const STALE_SECONDS = 600;

    public function __construct(private readonly ResourceConnection $resource)
    {
    }

    /**
     * The stored key of an idempotency key of the platform: by store view and customer, so a key of someone else
     * never finds this order.
     */
    public function key(int $storeId, string $who, string $idempotencyKey): string
    {
        return hash('sha256', $storeId . '|' . $who . '|' . $idempotencyKey);
    }

    /**
     * The order of the key: its id; null when the key is free (now reserved for this request); false while another
     * request with the key is placing the order.
     */
    public function reserve(string $key, int $storeId, string $source, ?string $assistant): int|false|null
    {
        $db = $this->resource->getConnection();
        $table = $this->resource->getTableName('onetrace_assistant_order');

        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                $db->insert($table, ['idempotency_key' => $key, 'store_id' => $storeId, 'source' => $source, 'assistant' => $assistant, 'created_at' => gmdate('Y-m-d H:i:s')]);

                return null;
            } catch (DuplicateException) {
                $row = $db->fetchRow($db->select()->from($table)->where('idempotency_key = ?', $key));

                if (!is_array($row)) {
                    continue;
                }

                if ($row['order_id'] !== null) {
                    return (int) $row['order_id'];
                }

                $placed = $row['quote_id'] !== null ? $this->orderOfQuote((int) $row['quote_id']) : null;

                if ($placed !== null) {
                    $db->update($table, ['order_id' => $placed], ['id = ?' => (int) $row['id']]);

                    return $placed;
                }

                if (strtotime((string) $row['created_at'] . ' UTC') > time() - self::STALE_SECONDS) {
                    return false;
                }

                $db->delete($table, ['id = ?' => (int) $row['id']]);
            }
        }

        return false;
    }

    /** Right before the submit: a repeat after a lost answer finds the order by its quote. */
    public function placing(string $key, int $quoteId): void
    {
        $db = $this->resource->getConnection();
        $db->update($this->resource->getTableName('onetrace_assistant_order'), ['quote_id' => $quoteId], ['idempotency_key = ?' => $key]);
    }

    public function placed(string $key, int $orderId): void
    {
        $db = $this->resource->getConnection();
        $db->update($this->resource->getTableName('onetrace_assistant_order'), ['order_id' => $orderId], ['idempotency_key = ?' => $key]);
    }

    /**
     * The request failed: the key is free for a repeat, unless Magento placed the order of its quote before the
     * failure (an observer after the submit) — then that order is the order of the key and its id is returned.
     */
    public function release(string $key): ?int
    {
        $db = $this->resource->getConnection();
        $table = $this->resource->getTableName('onetrace_assistant_order');
        $row = $db->fetchRow($db->select()->from($table)->where('idempotency_key = ?', $key));

        if (!is_array($row)) {
            return null;
        }

        $placed = $row['order_id'] !== null ? (int) $row['order_id'] : ($row['quote_id'] !== null ? $this->orderOfQuote((int) $row['quote_id']) : null);

        if ($placed !== null) {
            $db->update($table, ['order_id' => $placed], ['id = ?' => (int) $row['id']]);

            return $placed;
        }

        $db->delete($table, ['id = ?' => (int) $row['id']]);

        return null;
    }

    /**
     * Where the order of a quote came from, while it is being placed or after: null for other orders.
     *
     * @return array{source: string, assistant: string|null}|null
     */
    public function originOfQuote(int $quoteId): ?array
    {
        return $quoteId > 0 ? $this->origin('quote_id', $quoteId) : null;
    }

    /**
     * @return array{source: string, assistant: string|null}|null
     */
    public function originOfOrder(int $orderId): ?array
    {
        return $orderId > 0 ? $this->origin('order_id', $orderId) : null;
    }

    /**
     * @return array{source: string, assistant: string|null}|null
     */
    private function origin(string $column, int $id): ?array
    {
        $db = $this->resource->getConnection();
        $row = $db->fetchRow($db->select()->from($this->resource->getTableName('onetrace_assistant_order'), ['source', 'assistant'])->where($column . ' = ?', $id)->limit(1));

        return is_array($row) ? ['source' => (string) $row['source'], 'assistant' => $row['assistant'] !== null ? (string) $row['assistant'] : null] : null;
    }

    private function orderOfQuote(int $quoteId): ?int
    {
        $db = $this->resource->getConnection();
        $id = $db->fetchOne($db->select()->from($this->resource->getTableName('sales_order'), ['entity_id'])->where('quote_id = ?', $quoteId)->limit(1));

        return is_numeric($id) ? (int) $id : null;
    }
}
