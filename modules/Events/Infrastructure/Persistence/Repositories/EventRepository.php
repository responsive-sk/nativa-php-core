<?php

declare(strict_types = 1);

namespace Modules\Events\Infrastructure\Persistence\Repositories;

use Modules\Database\Contracts\ConnectionInterface;
use Modules\Database\Infrastructure\Persistence\Driver;
use Modules\Database\Infrastructure\Persistence\SqlDialect;
use Modules\Events\Domain\Entities\Event;
use Modules\Events\Infrastructure\Persistence\Statement;

/**
 * EventRepository: persistence for event records.
 *
 * The composite `(slug, locale)` is the natural key. `exists()` answers the
 * editor before a write; the unique index is the final authority under a race.
 */
final readonly class EventRepository
{
    private SqlDialect $dialect;

    public function __construct(private ConnectionInterface $connection)
    {
        $this->dialect = SqlDialect::forDriver(Driver::fromName($connection->driverName()));
    }

    public function insert(Event $event): void
    {
        if ($this->exists($event->slug, $event->locale)) {
            throw new \DomainException('An event with this slug already exists in this locale.');
        }

        $data = $event->toArray();
        $stmt = Statement::prepare(
            $this->connection,
            'INSERT INTO events (id, slug, locale, title, summary, body, starts_at, ends_at, venue, address,'
            . ' capacity, reservations_enabled, published, created_at, updated_at)'
            . ' VALUES (:id, :slug, :locale, :title, :summary, :body, :starts, :ends, :venue, :address,'
            . ' :capacity, :reservations, :published, :created, :updated)',
        );
        $stmt->execute([
            'id'           => $data['id'],
            'slug'         => $data['slug'],
            'locale'       => $data['locale'],
            'title'        => $data['title'],
            'summary'      => $data['summary'],
            'body'         => $data['body'],
            'starts'       => $data['starts_at'],
            'ends'         => $data['ends_at'],
            'venue'        => $data['venue'],
            'address'      => $data['address'],
            'capacity'     => $data['capacity'],
            'reservations' => $data['reservations_enabled'],
            'published'    => $data['published'],
            'created'      => $data['created_at'],
            'updated'      => $data['updated_at'],
        ]);
    }

    public function update(Event $event): void
    {
        $stmt = Statement::prepare(
            $this->connection,
            'UPDATE events SET slug = :slug, locale = :locale, title = :title, summary = :summary,'
            . ' body = :body, starts_at = :starts, ends_at = :ends, venue = :venue, address = :address,'
            . ' capacity = :capacity, reservations_enabled = :reservations, published = :published,'
            . ' updated_at = CURRENT_TIMESTAMP WHERE id = :id',
        );
        $stmt->execute([
            'id'           => $event->id,
            'slug'         => $event->slug,
            'locale'       => $event->locale,
            'title'        => $event->title,
            'summary'      => $event->summary,
            'body'         => $event->body,
            'starts'       => $event->startsAt->format('Y-m-d H:i:s'),
            'ends'         => $event->endsAt?->format('Y-m-d H:i:s'),
            'venue'        => $event->venue,
            'address'      => $event->address,
            'capacity'     => $event->capacity,
            'reservations' => $event->reservationsEnabled ? 1 : 0,
            'published'    => $event->published ? 1 : 0,
        ]);
    }

    public function findById(string $id): ?Event
    {
        $stmt = Statement::prepare($this->connection, 'SELECT * FROM events WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return $this->one($stmt->fetch());
    }

    public function findBySlug(string $slug, string $locale): ?Event
    {
        $stmt = Statement::prepare(
            $this->connection,
            'SELECT * FROM events WHERE slug = :slug AND locale = :locale',
        );
        $stmt->execute(['slug' => $slug, 'locale' => $locale]);

        return $this->one($stmt->fetch());
    }

    public function findPublished(string $slug, string $locale): ?Event
    {
        $stmt = Statement::prepare(
            $this->connection,
            'SELECT * FROM events WHERE slug = :slug AND locale = :locale AND published = 1',
        );
        $stmt->execute(['slug' => $slug, 'locale' => $locale]);

        return $this->one($stmt->fetch());
    }

    /**
     * The public listing: published, starting now or later, in one locale.
     *
     * @return list<Event>
     */
    public function upcomingPublished(string $locale, ?string $now = null): array
    {
        $stmt = Statement::prepare(
            $this->connection,
            'SELECT * FROM events WHERE locale = :locale AND published = 1 AND starts_at >= :now'
            . ' ORDER BY starts_at ASC, ' . $this->dialect->noCase('title') . ' ASC',
        );
        $stmt->execute([
            'locale' => $locale,
            'now'    => $now ?? date('Y-m-d H:i:s'),
        ]);

        return $this->many($stmt->fetchAll());
    }

    /**
     * Every event in one locale, newest start first, for the Mark index.
     *
     * @return list<Event>
     */
    public function listForLocale(string $locale): array
    {
        $stmt = Statement::prepare(
            $this->connection,
            'SELECT * FROM events WHERE locale = :locale ORDER BY starts_at DESC',
        );
        $stmt->execute(['locale' => $locale]);

        return $this->many($stmt->fetchAll());
    }

    public function exists(string $slug, string $locale, ?string $exceptId = null): bool
    {
        $sql = 'SELECT COUNT(*) FROM events WHERE slug = :slug AND locale = :locale';
        $params = ['slug' => $slug, 'locale' => $locale];

        if (null !== $exceptId) {
            $sql .= ' AND id <> :id';
            $params['id'] = $exceptId;
        }

        $stmt = Statement::prepare($this->connection, $sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn() > 0;
    }

    public function delete(string $id): void
    {
        Statement::prepare($this->connection, 'DELETE FROM events WHERE id = :id')->execute(['id' => $id]);
    }

    private function one(mixed $row): ?Event
    {
        return is_array($row) ? Event::fromArray($row) : null;
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<Event>
     */
    private function many(array $rows): array
    {
        $events = [];

        foreach ($rows as $row) {
            if (is_array($row)) {
                $events[] = Event::fromArray($row);
            }
        }

        return $events;
    }
}
