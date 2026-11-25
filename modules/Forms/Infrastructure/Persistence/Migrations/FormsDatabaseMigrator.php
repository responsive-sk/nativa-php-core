<?php

declare(strict_types = 1);

namespace Modules\Forms\Infrastructure\Persistence\Migrations;

use Modules\Core\Infrastructure\Attributes\AsMigrator;
use Modules\Core\Interfaces\CLI\CliOutput;
use Modules\Core\Interfaces\CLI\MigratorInterface;
use Modules\Database\Infrastructure\Persistence\SqlDialect;
use Modules\Forms\Domain\Model\FormSchema;

/**
 * Forms Database Migrator — creates the forms.db schema.
 *
 * Two tables:
 *
 *  - `forms` — one definition per `(slug, locale)`. The unique index is over
 *    the PAIR: the same reader-facing name exists once per language.
 *  - `form_submissions` — one row per accepted submission, carrying the
 *    definition id so a rename keeps its answers attached and a delete takes
 *    them with it.
 *
 * `published` is the only flag, and it is the page front matter's own word for
 * the same idea. Submission state is `status` with two named values, not a
 * second boolean, per `docs/boolean-vocabulary.md`.
 *
 * SQLite foreign keys are enabled on the connection, but this module does not
 * rely on `ON DELETE CASCADE` for the destructive path: the repository deletes
 * submissions and the definition inside one transaction, so the behaviour is
 * the same on a connection with foreign keys off (a plain `sqlite3` shell, a
 * test that builds its own PDO).
 *
 * Every statement is idempotent, so re-running never fails or duplicates.
 *
 * The one definition this migrator writes is the contact form a page names in
 * its front matter (`form: contact`). It is a DEPLOYMENT seed, not demo data:
 * a fresh site would otherwise publish a contact page with no box on it, and an
 * update would have to import the definition by hand. It is inserted only when
 * that `(slug, locale)` pair is missing, so an operator's edit survives; a
 * deleted definition comes back, because the page without a box is the failure
 * this seed exists to prevent.
 */
#[AsMigrator(module: 'forms', table: 'forms')]
final readonly class FormsDatabaseMigrator implements MigratorInterface
{
    private SqlDialect $dialect;

    private string $timestamp;

    public function __construct(private \PDO $pdo)
    {
        $this->dialect = SqlDialect::forPdo($pdo);
        $this->timestamp = $this->dialect->timestampType();
    }

    public function run(CliOutput $output): void
    {
        $output->writeln('<info>Migrating forms.db...</info>');

        $this->createFormsTable();
        $this->createSubmissionsTable();
        $this->createIndexes();
        $seeded = $this->seedDeploymentForms();

        $output->writeln('<info>  ✓ Created table: forms</info>');
        $output->writeln('<info>  ✓ Created table: form_submissions</info>');

        if ($seeded > 0) {
            $output->writeln(sprintf('<info>  ✓ Seeded %d deployment form(s)</info>', $seeded));
        }
    }

    private function createFormsTable(): void
    {
        $this->pdo->exec(<<<SQL
                CREATE TABLE IF NOT EXISTS forms (
                    id              VARCHAR(32) PRIMARY KEY,
                    slug            VARCHAR(80) NOT NULL,
                    locale          VARCHAR(10) NOT NULL,
                    title           VARCHAR(120) NOT NULL,
                    intro           VARCHAR(500) NOT NULL DEFAULT '',
                    success_message VARCHAR(300) NOT NULL DEFAULT '',
                    fields          TEXT NOT NULL,
                    published       INTEGER NOT NULL DEFAULT 0 CHECK (published IN (0, 1)),
                    created_at      {$this->timestamp} DEFAULT CURRENT_TIMESTAMP,
                    updated_at      {$this->timestamp} DEFAULT CURRENT_TIMESTAMP
                )
            SQL);
    }

    private function createSubmissionsTable(): void
    {
        $this->pdo->exec(<<<SQL
                CREATE TABLE IF NOT EXISTS form_submissions (
                    id          VARCHAR(32) PRIMARY KEY,
                    form_id     VARCHAR(32) NOT NULL,
                    form_slug   VARCHAR(80) NOT NULL,
                    locale      VARCHAR(10) NOT NULL,
                    payload     TEXT NOT NULL,
                    status      VARCHAR(12) NOT NULL DEFAULT 'new'
                                CHECK (status IN ('new', 'handled')),
                    ip_address  VARCHAR(45) DEFAULT NULL,
                    created_at  {$this->timestamp} DEFAULT CURRENT_TIMESTAMP,
                    handled_at  {$this->timestamp} DEFAULT NULL
                )
            SQL);
    }

    private function createIndexes(): void
    {
        $this->pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_forms_slug_locale ON forms(slug, locale)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_forms_locale ON forms(locale, published)');
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_submissions_form ON form_submissions(form_id, status, created_at)');
    }

    /**
     * Insert the definitions a fresh deployment needs, one per missing pair.
     *
     * The pair is the check, not the table: a site whose `contact` form an
     * editor renamed keeps that definition, because the seed never writes over
     * a row that exists.
     */
    private function seedDeploymentForms(): int
    {
        $exists = $this->pdo->prepare('SELECT 1 FROM forms WHERE slug = ? AND locale = ?');
        $insert = $this->pdo->prepare(
            'INSERT INTO forms (id, slug, locale, title, intro, success_message, fields, published)
             VALUES (?, ?, ?, ?, ?, ?, ?, 1)',
        );

        $seeded = 0;

        foreach ($this->deploymentForms() as $slug => $definition) {
            $exists->execute([$slug, 'sk_SK']);

            if (false !== $exists->fetchColumn()) {
                continue;
            }

            $insert->execute([
                bin2hex(random_bytes(16)),
                $slug,
                'sk_SK',
                $definition['title'],
                $definition['intro'],
                $definition['success_message'],
                FormSchema::fromRows($definition['fields'])->toJson(),
            ]);

            $seeded++;
        }

        return $seeded;
    }

    /**
     * Definitions the site ships with, keyed by slug.
     *
     * The field rows are the shape the admin editor posts, so the seed runs
     * through the same `FormSchema::fromRows()` normalisation an editor's save
     * does and cannot declare a schema the editor would refuse. `options` is a
     * newline-separated string for the same reason: that is one textarea, not a
     * list.
     *
     * @return array<string, array{title: string, intro: string, success_message: string, fields: list<array<string, mixed>>}>
     */
    private function deploymentForms(): array
    {
        return [
            'contact' => [
                'title'           => 'Kontaktný formulár',
                'intro'           => 'Napíšte nám. Odpovieme na e-mail, ktorý zadáte, verejne sa nezobrazí.',
                'success_message' => 'Ďakujeme, správa dorazila. Ozveme sa na e-mail, ktorý ste nechali.',
                'fields'          => [
                    ['key' => 'name', 'label' => 'Meno', 'type' => 'text', 'required' => true, 'max' => 120],
                    ['key' => 'email', 'label' => 'E-mail', 'type' => 'email', 'required' => true, 'max' => 200],
                    [
                        'key'      => 'topic',
                        'label'    => 'Téma otázky',
                        'type'     => 'select',
                        'required' => true,
                        'options'  => "Otázka k článku\nChyba v kóde\nSpolupráca\nNiečo iné",
                    ],
                    ['key' => 'message', 'label' => 'Správa', 'type' => 'textarea', 'required' => true, 'max' => 5000],
                    [
                        'key'      => 'consent',
                        'label'    => 'Súhlasím, aby ste moje meno a e-mail použili len na odpoveď.',
                        'type'     => 'checkbox',
                        'required' => true,
                    ],
                ],
            ],
        ];
    }
}
