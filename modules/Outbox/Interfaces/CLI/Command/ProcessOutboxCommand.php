<?php

declare(strict_types = 1);

namespace Modules\Outbox\Interfaces\CLI\Command;

use Modules\Outbox\Application\Services\ArticlePublishHandler;
use Modules\Outbox\Application\Services\OutboxDrain;
use Modules\Outbox\Interfaces\HTTP\OutboxServices;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * bin/cms outbox:process — drain the outbox queue and prune it.
 *
 * The kit ships no worker and no cron, so the in-request drain in
 * `OutboxDrain` is the normal delivery path. This command is the operator
 * side of the same queue: it makes the pending backlog observable, lets a
 * deployment without traffic still deliver events, and prunes rows that are
 * already `processed` so outbox.db does not grow without bound.
 *
 * Exits non-zero when messages failed, so a cron entry or a CI probe can
 * notice a poison message instead of silently rotating it.
 */
#[AsCommand(name: 'outbox:process', description: 'Deliver pending outbox messages and prune processed ones')]
final class ProcessOutboxCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Maximum messages to deliver in one pass', '100')
            ->addOption('prune-interval', null, InputOption::VALUE_REQUIRED, 'Delete processed messages older than this (SQLite interval)', '7 days')
            ->addOption('no-prune', null, InputOption::VALUE_NONE, 'Deliver only; keep processed rows for inspection');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $limit = max(1, (int) $this->text($input, 'limit', '100'));
        $repository = OutboxServices::repository();

        $before = $repository->countByStatus();
        $output->writeln(sprintf(
            '<info>Outbox before:</info> pending=%d processing=%d processed=%d failed=%d',
            $before['pending'],
            $before['processing'],
            $before['processed'],
            $before['failed'],
        ));

        ArticlePublishHandler::register(OutboxServices::processor());
        $this->reportHandlers($output);

        $result = OutboxDrain::drain($limit);
        $output->writeln(sprintf(
            '<info>Delivered:</info> processed=%d failed=%d (limit %d)',
            $result['processed'],
            $result['failed'],
            $limit,
        ));

        if (!$this->flag($input, 'no-prune')) {
            $pruned = $repository->cleanProcessed($this->text($input, 'prune-interval', '7 days'));
            $output->writeln(sprintf('<info>Pruned:</info> %d processed message(s)', $pruned));
        }

        $after = $repository->countByStatus();
        $output->writeln(sprintf(
            '<info>Outbox after:</info> pending=%d processing=%d processed=%d failed=%d',
            $after['pending'],
            $after['processing'],
            $after['processed'],
            $after['failed'],
        ));

        return 0 === $result['failed'] ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * Name the handler types this pass could deliver — a message whose type
     * is missing from the list is what will land in `failed` below.
     */
    private function reportHandlers(OutputInterface $output): void
    {
        $types = OutboxServices::processor()->registeredTypes();

        $output->writeln('Handlers: ' . ([] === $types ? '(none registered)' : implode(', ', $types)));
    }

    private function text(InputInterface $input, string $name, string $default): string
    {
        $value = $input->getOption($name);

        return is_scalar($value) ? (string) $value : $default;
    }

    /**
     * A VALUE_NONE option reads as bool; anything else is not a flag.
     */
    private function flag(InputInterface $input, string $name): bool
    {
        return true === $input->getOption($name);
    }
}
