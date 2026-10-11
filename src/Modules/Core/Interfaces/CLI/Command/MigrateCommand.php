<?php

declare(strict_types = 1);

namespace Modules\Core\Interfaces\CLI\Command;

use Modules\Core\Interfaces\CLI\Support\DatabaseMigrator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * bin/cms migrate — run all module database migrations.
 */
#[AsCommand(name: 'migrate', description: 'Run all module database migrations')]
final class MigrateCommand extends Command
{
    public function __construct(private readonly DatabaseMigrator $migrator = new DatabaseMigrator())
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<info>Running migrations...</info>');

        $this->migrator->run($output);

        return Command::SUCCESS;
    }
}
