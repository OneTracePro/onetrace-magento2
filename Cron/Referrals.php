<?php

declare(strict_types=1);

namespace OneTrace\Connector\Cron;

use OneTrace\Connector\Model\Referrals as Pending;

/**
 * Friend's codes of new customers waiting to be sent to the platform.
 */
class Referrals
{
    public function __construct(private readonly Pending $referrals)
    {
    }

    public function execute(): void
    {
        $this->referrals->process();
    }
}
