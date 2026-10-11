<?php

declare(strict_types = 1);

namespace Modules\Core\Interfaces\CLI;

/**
 * CliOutput — minimal output contract for migrator run() methods.
 *
 * Symfony Console's OutputInterface is structurally compatible (both expose
 * writeln(string)), so migrators accept CliOutput|OutputInterface. This
 * interface lets the auto-migration path (HTTP bootstrap, no symfony/console)
 * pass a lightweight logger instead of a full Console output.
 */
interface CliOutput
{
    /**
     * Write a single line of output.
     */
    public function writeln(string $message): void;
}
