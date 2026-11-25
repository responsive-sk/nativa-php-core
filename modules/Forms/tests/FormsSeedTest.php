<?php

declare(strict_types = 1);

namespace Modules\Forms\Tests;

use Modules\Core\Interfaces\CLI\CliOutput;
use Modules\Database\Infrastructure\Persistence\DatabaseConnectionManager;
use Modules\Forms\Application\FormsServices;
use Modules\Forms\Domain\Entities\FormField;
use Modules\Forms\Domain\Model\FormSchema;
use Modules\Forms\Infrastructure\Persistence\Migrations\FormsDatabaseMigrator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Support\SqliteDatabase;

/**
 * FormsSeedTest — the definition the deployment ships, against a throwaway file.
 *
 * The contact form is the one definition a fresh site must have: the contact
 * page names it in its front matter, so a database without it publishes a page
 * with no box. The second case is the other half of the promise: the seed fills
 * a missing pair and never takes an operator's edit back.
 */
final class FormsSeedTest extends TestCase
{
    use SqliteDatabase;

    private string $dir;

    protected function setUp(): void
    {
        $this->pinSqliteDatabase();

        if (!is_dir(APP_BASE_PATH . '/src/Modules/Forms')) {
            $this->markTestSkipped('Forms is not installed.');
        }

        $this->dir = sys_get_temp_dir() . '/forms-seed-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0o777, true);

        putenv('DB_FORMS=' . $this->dir . '/forms.db');
        DatabaseConnectionManager::resetInstance();
        FormsServices::reset();
    }

    protected function tearDown(): void
    {
        $this->restoreDatabaseEnvironment();

        if (!isset($this->dir)) {
            return;
        }

        FormsServices::reset();
        DatabaseConnectionManager::resetInstance();
        putenv('DB_FORMS');

        foreach (glob($this->dir . '/*') ?: [] as $file) {
            is_file($file) && unlink($file);
        }

        is_dir($this->dir) && rmdir($this->dir);

        $this->restoreDatabaseEnvironment();
    }

    #[Test]
    public function theDeploymentContactFormIsSeeded(): void
    {
        $this->migrate();

        $row = DatabaseConnectionManager::instance()
            ->connection('forms')
            ->query("SELECT * FROM forms WHERE slug = 'contact' AND locale = 'sk_SK'")
            ->fetch(\PDO::FETCH_ASSOC);

        self::assertIsArray($row);
        self::assertSame('Kontaktný formulár', $row['title']);
        self::assertSame(1, (int) $row['published']);

        $schema = FormSchema::fromJson((string) $row['fields']);

        self::assertTrue($schema->isValid());
        self::assertSame(['name', 'email', 'topic', 'message', 'consent'], array_map(
            static fn (FormField $field): string => $field->key,
            $schema->fields(),
        ));
    }

    #[Test]
    public function anEditedDefinitionIsNotOverwritten(): void
    {
        $this->migrate();

        $pdo = DatabaseConnectionManager::instance()->connection('forms');
        $pdo->exec("UPDATE forms SET title = 'Kontakt redakcie' WHERE slug = 'contact' AND locale = 'sk_SK'");

        $this->migrate();

        $titles = $pdo->query("SELECT title FROM forms WHERE slug = 'contact' AND locale = 'sk_SK'")
            ->fetchAll(\PDO::FETCH_COLUMN);

        self::assertSame(['Kontakt redakcie'], $titles);
    }

    private function migrate(): void
    {
        $output = new class implements CliOutput {
            public function writeln(string $message): void
            {
            }
        };

        (new FormsDatabaseMigrator(DatabaseConnectionManager::instance()->connection('forms')))->run($output);
    }
}
