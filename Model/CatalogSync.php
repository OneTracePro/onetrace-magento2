<?php

declare(strict_types=1);

namespace OneTrace\Connector\Model;

/**
 * The whole catalog into the queue: every visible enabled product of each store view the catalog goes to.
 */
class CatalogSync
{
    public const PAGE = 500;

    public function __construct(private readonly Config $config, private readonly Catalog $catalog, private readonly Queue $queue)
    {
    }

    /**
     * @return int products queued
     */
    public function queueAll(): int
    {
        $count = 0;
        $seen = [];

        foreach ($this->config->catalogStores() as $storeId) {
            $page = 1;

            do {
                $ids = $this->catalog->page($storeId, $page++, self::PAGE);

                foreach ($ids as $id) {
                    if (!isset($seen[$id])) {
                        $seen[$id] = true;
                        $this->queue->product($id);
                        ++$count;
                    }
                }
            } while (count($ids) === self::PAGE);
        }

        return $count;
    }
}
