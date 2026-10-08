<?php

declare(strict_types=1);

namespace OneTrace\Connector\Console;

use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use OneTrace\Connector\Model\CatalogSync;
use OneTrace\Connector\Model\Queue;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class SyncCatalogCommand extends Command
{
    public function __construct(private readonly CatalogSync $sync, private readonly Queue $queue, private readonly State $state)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('onetrace:sync-catalog')->setDescription('Uploads the whole product catalog to OneTrace now');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->state->getAreaCode();
        } catch (\Magento\Framework\Exception\LocalizedException) {
            $this->state->setAreaCode(Area::AREA_CRONTAB);
        }

        $count = $this->sync->queueAll();
        $result = $this->queue->flush(PHP_INT_MAX);
        $output->writeln(sprintf('<info>%d products queued; sent %d, to retry %d, dropped %d.</info>', $count, $result['sent'], $result['retried'], $result['dropped']));

        return Command::SUCCESS;
    }
}
