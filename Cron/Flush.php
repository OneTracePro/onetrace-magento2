<?php

declare(strict_types=1);

namespace OneTrace\Connector\Cron;

use OneTrace\Connector\Model\Queue;

class Flush
{
    public function __construct(private readonly Queue $queue)
    {
    }

    public function execute(): void
    {
        $this->queue->flush();
    }
}
