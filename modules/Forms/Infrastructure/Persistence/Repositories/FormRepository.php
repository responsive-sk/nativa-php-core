<?php

declare(strict_types = 1);

namespace Modules\Forms\Infrastructure\Persistence\Repositories;

use Modules\Database\Contracts\ConnectionInterface;
use Modules\Database\Contracts\StatementInterface;
use Modules\Forms\Domain\Entities\Form;
use Modules\Forms\Infrastructure\Persistence\Statement;

/**
 * FormRepository — SQLite persistence for form definitions.
 *
 * Concrete class, prepared statements only, no string-built SQL. A definition
 * is small and read on every render, so there is no cache here: the page cache
 * already holds the rendered HTML, and a second cache would be a second thing
 * to flush.
 */
final readonly class FormRepository
{
    public function __construct(private ConnectionInterface $connection)
    {
    }

    public function insert(Form $form): void
    {
        $stmt = Statement::prepare($this->connection,
            'INSERT INTO forms (id, slug, locale, title, intro, success_message, fields, published)'
            . ' VALUES (:id, :slug, :locale, :title, :intro, :success, :fields, :published)',
        );
        $stmt->execute([
            'id'        => $form->id,
            'slug'      => $form->slug,
            'locale'    => $form->locale,
            'title'     => $form->title,
            'intro'     => $form->intro,
            'success'   => $form->successMessage,
            'fields'    => $form->schema->toJson(),
            'published' => $form->published ? 1 : 0,
        ]);
    }

    public function update(Form $form): void
    {
        $stmt = Statement::prepare($this->connection,
            'UPDATE forms SET slug = :slug, locale = :locale, title = :title, intro = :intro,'
            . ' success_message = :success, fields = :fields, published = :published,'
            . ' updated_at = CURRENT_TIMESTAMP WHERE id = :id',
        );
        $stmt->execute([
            'id'        => $form->id,
            'slug'      => $form->slug,
            'locale'    => $form->locale,
            'title'     => $form->title,
            'intro'     => $form->intro,
            'success'   => $form->successMessage,
            'fields'    => $form->schema->toJson(),
            'published' => $form->published ? 1 : 0,
        ]);
    }

    public function findById(string $id): ?Form
    {
        $stmt = Statement::prepare($this->connection, 'SELECT * FROM forms WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return $this->one($stmt);
    }

    public function findBySlug(string $slug, string $locale): ?Form
    {
        $stmt = Statement::prepare($this->connection,
            'SELECT * FROM forms WHERE slug = :slug AND locale = :locale',
        );
        $stmt->execute(['slug' => $slug, 'locale' => $locale]);

        return $this->one($stmt);
    }

    /**
     * The one lookup a public render performs: a slug that exists, is
     * published and belongs to this locale.
     */
    public function findPublished(string $slug, string $locale): ?Form
    {
        $stmt = Statement::prepare($this->connection,
            'SELECT * FROM forms WHERE slug = :slug AND locale = :locale AND published = 1',
        );
        $stmt->execute(['slug' => $slug, 'locale' => $locale]);

        return $this->one($stmt);
    }

    /**
     * @return list<Form>
     */
    public function listForLocale(string $locale): array
    {
        $stmt = Statement::prepare($this->connection,
            'SELECT * FROM forms WHERE locale = :locale ORDER BY title COLLATE NOCASE',
        );
        $stmt->execute(['locale' => $locale]);

        $forms = [];

        foreach ($stmt->fetchAll() as $row) {
            if (is_array($row)) {
                $forms[] = Form::fromArray($row);
            }
        }

        return $forms;
    }

    /**
     * Is this slug taken in this locale? `$exceptId` lets an edit keep its own
     * slug without colliding with itself.
     */
    public function exists(string $slug, string $locale, ?string $exceptId = null): bool
    {
        $sql = 'SELECT COUNT(*) FROM forms WHERE slug = :slug AND locale = :locale';
        $params = ['slug' => $slug, 'locale' => $locale];

        if (null !== $exceptId) {
            $sql .= ' AND id <> :id';
            $params['id'] = $exceptId;
        }

        $stmt = Statement::prepare($this->connection, $sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * Delete a definition and everything filed against it, in one transaction.
     *
     * Explicit rather than `ON DELETE CASCADE`: the manager's connections
     * enable foreign keys, but a connection built elsewhere may not, and a
     * removal whose correctness depends on a pragma is a removal that is wrong
     * in a shell.
     */
    public function delete(string $id): void
    {
        $this->connection->beginTransaction();

        try {
            Statement::prepare($this->connection, 'DELETE FROM form_submissions WHERE form_id = :id')
                ->execute(['id' => $id]);
            Statement::prepare($this->connection, 'DELETE FROM forms WHERE id = :id')
                ->execute(['id' => $id]);

            $this->connection->commit();
        } catch (\Throwable $e) {
            $this->connection->rollBack();

            throw $e;
        }
    }

    private function one(StatementInterface $stmt): ?Form
    {
        $row = $stmt->fetch();

        return is_array($row) ? Form::fromArray($row) : null;
    }
}
