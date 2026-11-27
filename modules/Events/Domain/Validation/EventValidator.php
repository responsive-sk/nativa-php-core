<?php

declare(strict_types = 1);

namespace Modules\Events\Domain\Validation;

use DateTimeImmutable;

/**
 * EventValidator: the event editor's one set of rules.
 *
 * The repository enforces the composite uniqueness because that is a database
 * fact; this validator answers the field rules an editor can fix without a
 * write. The same class is used by the create and edit actions, so a rule
 * cannot exist on one form and be absent from the other.
 */
final class EventValidator
{
    /**
     * @param array<string, mixed> $data
     *
     * @return list<string>
     */
    public static function errors(array $data): array
    {
        $errors = [];
        $slug = trim((string) ($data['slug'] ?? ''));
        $title = trim((string) ($data['title'] ?? ''));
        $starts = self::date($data['starts_at'] ?? null);
        $ends = self::date($data['ends_at'] ?? null);

        if ('' === $slug) {
            $errors[] = 'Slug is required.';
        } elseif (1 !== preg_match('/^[a-z0-9][a-z0-9_-]*$/', $slug)) {
            $errors[] = 'Slug may contain only lowercase letters, digits, hyphens and underscores.';
        } elseif (\strlen($slug) > 80) {
            $errors[] = 'Slug is too long (max 80 characters).';
        }

        if ('' === $title) {
            $errors[] = 'Title is required.';
        } elseif (mb_strlen($title) > 160) {
            $errors[] = 'Title is too long (max 160 characters).';
        }

        if (mb_strlen(trim((string) ($data['summary'] ?? ''))) > 500) {
            $errors[] = 'Summary is too long (max 500 characters).';
        }

        if (mb_strlen(trim((string) ($data['venue'] ?? ''))) > 160) {
            $errors[] = 'Venue is too long (max 160 characters).';
        }

        if (mb_strlen(trim((string) ($data['address'] ?? ''))) > 240) {
            $errors[] = 'Address is too long (max 240 characters).';
        }

        if (null === $starts) {
            $errors[] = 'Start time is required and must be a valid date.';
        }

        if (null !== ($data['ends_at'] ?? null) && '' !== trim((string) $data['ends_at']) && null === $ends) {
            $errors[] = 'End time must be a valid date.';
        }

        if (null !== $starts && null !== $ends && $ends < $starts) {
            $errors[] = 'End time cannot be before the start time.';
        }

        $capacity = filter_var($data['capacity'] ?? null, FILTER_VALIDATE_INT);

        if (false === $capacity || $capacity < 1) {
            $errors[] = 'Capacity must be a whole number greater than zero.';
        }

        return $errors;
    }

    /**
     * The normalised editor values, ready for `Event::create()` or a rebuilt
     * entity. Dates stay strings in the storage shape; the entity parses them.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public static function normalize(array $data): array
    {
        return [
            'slug'                 => strtolower(trim((string) ($data['slug'] ?? ''))),
            'title'                => trim((string) ($data['title'] ?? '')),
            'summary'              => trim((string) ($data['summary'] ?? '')),
            'body'                 => (string) ($data['body'] ?? ''),
            'starts_at'            => trim((string) ($data['starts_at'] ?? '')),
            'ends_at'              => '' === trim((string) ($data['ends_at'] ?? '')) ? null : trim((string) $data['ends_at']),
            'venue'                => trim((string) ($data['venue'] ?? '')),
            'address'              => trim((string) ($data['address'] ?? '')),
            'capacity'             => (int) ($data['capacity'] ?? 0),
            'reservations_enabled' => self::flag($data['reservations_enabled'] ?? false),
            'published'            => self::flag($data['published'] ?? false),
        ];
    }

    public static function date(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value) || '' === trim($value)) {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private static function flag(mixed $value): bool
    {
        return true === $value || 1 === $value || '1' === $value || 'true' === $value || 'on' === $value;
    }
}
