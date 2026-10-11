<?php

declare(strict_types = 1);

namespace Modules\Locales\Infrastructure\Persistence\Repositories;

use Modules\Locales\Domain\Model\Translation;

/**
 * TranslationRepository — SQLite persistence for translations.
 *
 * Concrete class (no interface — C-pattern: an internal single-implementation
 * interface is speculative abstraction; call the class directly).
 */
final readonly class TranslationRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    /**
     * Find a translation by id.
     */
    public function findById(string $id): ?Translation
    {
        $stmt = $this->pdo->prepare('SELECT * FROM translations WHERE id = :id');
        $stmt->execute(['id' => $id]);
        /** @var array<string, mixed>|false $row */
        $row = $stmt->fetch();

        return is_array($row) ? Translation::fromArray($row) : null;
    }

    /**
     * Find a translation by key and locale.
     */
    public function findByKey(string $key, string $locale): ?Translation
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM translations WHERE translation_key = :key AND locale = :locale',
        );
        $stmt->execute(['key' => $key, 'locale' => $locale]);
        /** @var array<string, mixed>|false $row */
        $row = $stmt->fetch();

        return is_array($row) ? Translation::fromArray($row) : null;
    }

    /**
     * All translations for a locale, keyed by translation key.
     *
     * @return list<Translation>
     */
    public function findByLocale(string $locale): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM translations WHERE locale = :locale ORDER BY translation_key ASC',
        );
        $stmt->execute(['locale' => $locale]);

        return $this->hydrateAll($stmt);
    }

    /**
     * Translations by group and locale.
     *
     * @return list<Translation>
     */
    public function findByGroup(string $group, string $locale): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM translations WHERE group_name = :group AND locale = :locale ORDER BY translation_key ASC',
        );
        $stmt->execute(['group' => $group, 'locale' => $locale]);

        return $this->hydrateAll($stmt);
    }

    /**
     * Search translations by key or value within a locale.
     *
     * @return list<Translation>
     */
    public function search(string $query, string $locale): array
    {
        $like = '%' . $query . '%';
        $stmt = $this->pdo->prepare(
            'SELECT * FROM translations WHERE locale = :locale AND (translation_key LIKE :query OR value LIKE :query2) ORDER BY translation_key ASC',
        );
        $stmt->execute(['locale' => $locale, 'query' => $like, 'query2' => $like]);

        return $this->hydrateAll($stmt);
    }

    /**
     * All unique translation keys across all locales.
     *
     * @return list<string>
     */
    public function findAllKeys(): array
    {
        $stmt = $this->pdo->query('SELECT DISTINCT translation_key FROM translations ORDER BY translation_key ASC');

        // array_values guarantees a list (FETCH_COLUMN may not be proven a list by PHPStan).
        return false === $stmt ? [] : array_values($stmt->fetchAll(\PDO::FETCH_COLUMN, 0));
    }

    /**
     * Insert or update a translation.
     */
    public function save(Translation $translation): void
    {
        $data = $translation->toArray();
        $existing = $this->findById($translation->id());

        if (null !== $existing) {
            $stmt = $this->pdo->prepare(
                'UPDATE translations SET value = :value, group_name = :group_name, updated_at = :updated_at WHERE id = :id',
            );
            $stmt->execute([
                'value'      => $data['value'],
                'group_name' => $data['group_name'],
                'updated_at' => $data['updated_at'],
                'id'         => $data['id'],
            ]);

            return;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO translations (id, translation_key, locale, value, group_name, created_at, updated_at)
             VALUES (:id, :key, :locale, :value, :group, :created, :updated)',
        );
        $stmt->execute([
            'id'      => $data['id'],
            'key'     => $data['translation_key'],
            'locale'  => $data['locale'],
            'value'   => $data['value'],
            'group'   => $data['group_name'],
            'created' => $data['created_at'],
            'updated' => $data['updated_at'],
        ]);
    }

    /**
     * Delete a translation by id.
     */
    public function delete(string $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM translations WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    /**
     * Count translations for a locale.
     */
    public function count(string $locale): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM translations WHERE locale = :locale');
        $stmt->execute(['locale' => $locale]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @param \PDOStatement $stmt
     *
     * @return list<Translation>
     */
    private function hydrateAll(\PDOStatement $stmt): array
    {
        $translations = [];

        foreach ($stmt->fetchAll() as $row) {
            $translations[] = Translation::fromArray($row);
        }

        return $translations;
    }
}
