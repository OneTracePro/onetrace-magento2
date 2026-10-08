<?php

declare(strict_types=1);

namespace OneTrace\Connector\Console;

use OneTrace\Connector\Model\Queue;
use OneTrace\Connector\Model\Status;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class StatusCommand extends Command
{
    public function __construct(private readonly Queue $queue, private readonly Status $status)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('onetrace:status')->setDescription('Shows the OneTrace queue and the last error');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $stats = $this->queue->stats();
        $output->writeln(sprintf('Queue: %d waiting, %d after an error.', $stats['waiting'], $stats['retrying']));

        if (($error = $this->queue->lastError()) !== null) {
            $output->writeln('Last error: ' . $error);
        }

        foreach ($this->status->storeIds() as $storeId) {
            if (($reason = $this->queue->pausedReason($storeId)) !== null) {
                $output->writeln(sprintf('<comment>Store view %d: sending is paused: %s</comment>', $storeId, $reason));
            }
        }

        return Command::SUCCESS;
    }
}
