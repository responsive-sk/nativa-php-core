<?php

declare(strict_types = 1);

namespace Modules\Locales\Application\Services;

use Modules\Locales\Application\DTOs\TranslationDTO;
use Modules\Locales\Domain\Model\Locale;
use Modules\Locales\Domain\Model\Translation;
use Modules\Locales\Infrastructure\Persistence\Repositories\TranslationRepository;
use Modules\Locales\Infrastructure\Services\JsonTranslationLoader;

/**
 * TranslationService — orchestrates translation CRUD with fallback support.
 *
 * Provides a high-level API for reading and writing translations with a
 * fallback chain for reads: per-request cache → database → JSON file →
 * default → raw key. Writes invalidate the per-request cache for the locale.
 */
final class TranslationService
{
    /** @var array<string, array<string, string>> locale → key → value request cache */
    private array $cache = [];

    public function __construct(
        private readonly TranslationRepository $repository,
        private readonly ?JsonTranslationLoader $jsonLoader = null,
    ) {
    }

    /**
     * Get translation value by key and locale with fallback.
     *
     * @param string $key     Translation key (e.g. nav.home)
     * @param string $locale  Locale code (e.g. sk_SK)
     * @param string $default Default value if no translation found
     *
     * @return string Translated value
     */
    public function get(string $key, string $locale, string $default = ''): string
    {
        if (isset($this->cache[$locale][$key])) {
            return $this->cache[$locale][$key];
        }

        $translation = $this->repository->findByKey($key, $locale);

        if (null !== $translation) {
            return $this->cache[$locale][$key] = $translation->value();
        }

        if (null !== $this->jsonLoader) {
            $fallback = $this->jsonLoader->load($locale);

            if (isset($fallback[$key])) {
                return $this->cache[$locale][$key] = $fallback[$key];
            }
        }

        return '' !== $default ? $default : $key;
    }

    /**
     * Set translation value — creates or updates.
     *
     * @throws \InvalidArgumentException When the locale code format or key format is invalid
     */
    public function set(string $key, string $localeCode, string $value, string $group = 'general'): void
    {
        $locale = Locale::fromString($localeCode);

        $existing = $this->repository->findByKey($key, $localeCode);

        if (null !== $existing) {
            $existing->updateValue($value);
            $this->repository->save($existing);
        } else {
            $this->repository->save(Translation::create($key, $locale, $value, $group));
        }

        $this->invalidate($localeCode);
    }

    /**
     * Find a translation by id (admin edit form).
     */
    public function findById(string $id): ?Translation
    {
        return $this->repository->findById($id);
    }

    /**
     * Save an existing entity directly (admin edit form updates value/group).
     */
    public function save(Translation $translation): void
    {
        $this->repository->save($translation);
        $this->invalidate($translation->locale()->code());
    }

    /**
     * Delete translation by key and locale.
     */
    public function delete(string $key, string $locale): void
    {
        $existing = $this->repository->findByKey($key, $locale);

        if (null !== $existing) {
            $this->repository->delete($existing->id());
            $this->invalidate($locale);
        }
    }

    /**
     * Get all translations for a locale as DTOs.
     *
     * @return list<TranslationDTO>
     */
    public function getAll(string $locale): array
    {
        return array_map(
            self::toDTO(...),
            $this->repository->findByLocale($locale),
        );
    }

    /**
     * Get translations by group for a locale as DTOs.
     *
     * @return list<TranslationDTO>
     */
    public function getByGroup(string $group, string $locale): array
    {
        return array_map(
            self::toDTO(...),
            $this->repository->findByGroup($group, $locale),
        );
    }

    /**
     * Search translations by key or value.
     *
     * @return list<TranslationDTO>
     */
    public function search(string $query, string $locale): array
    {
        return array_map(
            self::toDTO(...),
            $this->repository->search($query, $locale),
        );
    }

    /**
     * Get keys present in other locales but missing in the given locale.
     *
     * @return list<string>
     */
    public function getMissingKeys(string $locale): array
    {
        $allKeys = $this->repository->findAllKeys();
        $existingKeys = array_map(
            static fn (Translation $t): string => $t->key(),
            $this->repository->findByLocale($locale),
        );

        return array_values(array_diff($allKeys, $existingKeys));
    }

    /**
     * Convert a Translation entity to a DTO.
     */
    private static function toDTO(Translation $translation): TranslationDTO
    {
        return new TranslationDTO(
            id: $translation->id(),
            key: $translation->key(),
            locale: $translation->locale()->code(),
            value: $translation->value(),
            group: $translation->group(),
            createdAt: $translation->createdAt()->format('Y-m-d H:i:s'),
            updatedAt: $translation->updatedAt()->format('Y-m-d H:i:s'),
        );
    }

    /**
     * Drop the request cache for a locale after a write.
     */
    private function invalidate(string $locale): void
    {
        unset($this->cache[$locale]);
    }
}
