<?php

declare(strict_types=1);

namespace OneTrace\Connector\Console;

use OneTrace\Connector\Model\Queue;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class FlushCommand extends Command
{
    public function __construct(private readonly Queue $queue)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('onetrace:flush')->setDescription('Sends everything that is due in the OneTrace queue now');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->queue->resume();
        $result = $this->queue->flush(PHP_INT_MAX);
        $output->writeln(sprintf('<info>Sent %d, to retry %d, dropped %d.</info>', $result['sent'], $result['retried'], $result['dropped']));

        return Command::SUCCESS;
    }
}
