<?php

declare(strict_types=1);

namespace OneTrace\Connector\Cron;

use OneTrace\Connector\Model\CatalogSync;

class DailyCatalog
{
    public function __construct(private readonly CatalogSync $sync)
    {
    }

    public function execute(): void
    {
        $this->sync->queueAll();
    }
}
