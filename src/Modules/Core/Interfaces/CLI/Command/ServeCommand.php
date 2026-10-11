<?php

declare(strict_types = 1);

namespace Modules\Core\Interfaces\CLI\Command;

use Modules\Core\Infrastructure\Paths\AppPaths;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * bin/cms serve — run the PHP built-in development server.
 *
 * Mirrors `php -S host:port -t public router`; the built-in server serves
 * existing files under public/ directly and delegates everything else to
 * index.php. The router script (bin/dev/dev-server-router.php) hardens the
 * built-in server: dotfiles (e.g. /.htaccess) return 404 instead of being
 * served as static files (audit finding F-6).
 */
#[AsCommand(name: 'serve', description: 'Run the PHP built-in development server')]
final class ServeCommand extends Command
{
    protected function configure(): void
    {
        $this->addOption('host', null, InputOption::VALUE_REQUIRED, 'Host to bind', '127.0.0.1');
        $this->addOption('port', null, InputOption::VALUE_REQUIRED, 'Port to bind', '8000');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $host = $input->getOption('host');
        $port = $input->getOption('port');

        if (!is_string($host) || !is_string($port)) {
            throw new \InvalidArgumentException('--host and --port must be strings.');
        }

        $publicDir = AppPaths::instance()->public();
        $router = AppPaths::instance()->join('bin', 'dev', 'dev-server-router.php');
        $cmd = sprintf(
            '%s -S %s:%s -t %s %s',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($host),
            escapeshellarg($port),
            escapeshellarg($publicDir),
            escapeshellarg($router),
        );

        $output->writeln(sprintf('<info>Serving on http://%s:%s</info> (document root: %s)', $host, $port, $publicDir));
        $output->writeln('Press Ctrl+C to stop.');

        passthru($cmd, $exitCode);

        return 0 === $exitCode ? Command::SUCCESS : Command::FAILURE;
    }
}
