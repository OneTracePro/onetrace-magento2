<?php

declare(strict_types=1);

namespace OneTrace\Connector\Cron;

use OneTrace\Connector\Model\PromoSync as Sync;

/**
 * New and changed codes of OneTrace promo code pools as coupons of cart price rules.
 */
class PromoSync
{
    public function __construct(private readonly Sync $sync)
    {
    }

    public function execute(): void
    {
        $this->sync->run();
    }
}
