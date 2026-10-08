<?php

declare(strict_types=1);

namespace OneTrace\Connector\Console;

use OneTrace\Connector\Model\Status;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class TestCommand extends Command
{
    public function __construct(private readonly Status $status)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('onetrace:test')->setDescription('Checks the OneTrace keys of a store view')
            ->addOption('store', null, InputOption::VALUE_REQUIRED, 'Store view id', '1');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $ok = true;

        foreach ($this->status->check((int) $input->getOption('store')) as $result) {
            $output->writeln(($result['ok'] ? '<info>✓</info> ' : '<error>✗</error> ') . $result['message']);
            $ok = $ok && $result['ok'];
        }

        return $ok ? Command::SUCCESS : Command::FAILURE;
    }
}
