<?php

declare(strict_types=1);

namespace OneTrace\Connector\Cron;

use OneTrace\Connector\Model\Shipments as Pending;

/**
 * Shipments that got no tracking number within an hour are sent without it.
 */
class Shipments
{
    public function __construct(private readonly Pending $shipments)
    {
    }

    public function execute(): void
    {
        $this->shipments->due();
    }
}
