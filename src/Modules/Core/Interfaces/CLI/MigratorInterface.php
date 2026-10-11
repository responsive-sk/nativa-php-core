<?php

declare(strict_types = 1);

namespace Modules\Core\Interfaces\CLI;

use Symfony\Component\Console\Output\OutputInterface;

/**
 * MigratorInterface — explicit contract for module database migrators.
 *
 * Migrator classes are annotated #[AsMigrator(module:, table:)] AND
 * implement this interface, so the runner can enforce the contract and
 * phpstan can type the constructor + run() dispatch without ignores.
 */
interface MigratorInterface
{
    /**
     * Every migrator receives the PDO connection of its module database.
     */
    public function __construct(\PDO $pdo);

    /**
     * Create/update the module schema using idempotent
     * CREATE TABLE IF NOT EXISTS statements.
     */
    public function run(CliOutput | OutputInterface $output): void;
}
