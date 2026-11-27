<?php

declare(strict_types = 1);

namespace Modules\Events\Domain\Entities;

use DateTimeImmutable;

/**
 * Event: one dated programme item owned by a locale.
 *
 * The slug is unique only within a locale, matching Pages and Forms: the same
 * reader-facing name exists once per language. Dates are site-local values in
 * `Y-m-d H:i:s`, the shape the rest of this tree stores.
 */
// One event row is one entity: splitting the columns would move the published
// flag and the capacity away from the record they govern.
final readonly class Event
{
    public function __construct(
        public string $id,
        public string $slug,
        public string $locale,
        public string $title,
        public string $summary,
        public string $body,
        public DateTimeImmutable $startsAt,
        public ?DateTimeImmutable $endsAt,
        public string $venue,
        public string $address,
        public int $capacity,
        public bool $reservationsEnabled,
        public bool $published,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function create(string $locale, array $data): self
    {
        $now = new DateTimeImmutable();

        return new self(
            bin2hex(random_bytes(16)),
            (string) ($data['slug'] ?? ''),
            $locale,
            (string) ($data['title'] ?? ''),
            (string) ($data['summary'] ?? ''),
            (string) ($data['body'] ?? ''),
            self::date($data['starts_at'] ?? null) ?? $now,
            self::date($data['ends_at'] ?? null),
            (string) ($data['venue'] ?? ''),
            (string) ($data['address'] ?? ''),
            (int) ($data['capacity'] ?? 0),
            self::flag($data['reservations_enabled'] ?? true),
            self::flag($data['published'] ?? false),
            $now,
            $now,
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromArray(array $row): self
    {
        $created = self::date($row['created_at'] ?? null) ?? new DateTimeImmutable();

        return new self(
            (string) ($row['id'] ?? ''),
            (string) ($row['slug'] ?? ''),
            (string) ($row['locale'] ?? ''),
            (string) ($row['title'] ?? ''),
            (string) ($row['summary'] ?? ''),
            (string) ($row['body'] ?? ''),
            self::date($row['starts_at'] ?? null) ?? $created,
            self::date($row['ends_at'] ?? null),
            (string) ($row['venue'] ?? ''),
            (string) ($row['address'] ?? ''),
            (int) ($row['capacity'] ?? 0),
            self::flag($row['reservations_enabled'] ?? 1),
            self::flag($row['published'] ?? 0),
            $created,
            self::date($row['updated_at'] ?? null) ?? $created,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id'                   => $this->id,
            'slug'                 => $this->slug,
            'locale'               => $this->locale,
            'title'                => $this->title,
            'summary'              => $this->summary,
            'body'                 => $this->body,
            'starts_at'            => $this->startsAt->format('Y-m-d H:i:s'),
            'ends_at'              => $this->endsAt?->format('Y-m-d H:i:s'),
            'venue'                => $this->venue,
            'address'              => $this->address,
            'capacity'             => $this->capacity,
            'reservations_enabled' => $this->reservationsEnabled ? 1 : 0,
            'published'            => $this->published ? 1 : 0,
            'created_at'           => $this->createdAt->format('Y-m-d H:i:s'),
            'updated_at'           => $this->updatedAt->format('Y-m-d H:i:s'),
        ];
    }

    public function isPublished(): bool
    {
        return $this->published;
    }

    public function isUpcoming(?DateTimeImmutable $now = null): bool
    {
        return $this->startsAt >= ($now ?? new DateTimeImmutable());
    }

    public function takesReservations(): bool
    {
        return $this->reservationsEnabled;
    }

    public function reservationsOpen(?DateTimeImmutable $now = null): bool
    {
        return $this->published && $this->reservationsEnabled && $this->isUpcoming($now);
    }

    public function remainingSeats(int $held): int
    {
        return max(0, $this->capacity - $held);
    }

    public function startsAtLabel(): string
    {
        return $this->startsAt->format('Y-m-d H:i');
    }

    public function endsAtLabel(): string
    {
        return $this->endsAt?->format('Y-m-d H:i') ?? '';
    }

    private static function date(mixed $value): ?DateTimeImmutable
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
        return true === $value || 1 === $value || '1' === $value || 'true' === $value;
    }
}
