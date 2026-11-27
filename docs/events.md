# Events > programme and reservations

`Modules\Events` publishes a client's programme and takes reservations without a
second system beside the CMS. The module owns both halves: one `events.db`
answers the event record and the seats its reservations hold.

## The data

`storage/data/events.db` carries two tables.

### `events`

| column | rule |
|---|---|
| `slug` | lowercase path segment, unique with `locale` |
| `locale` | canonical locale code (`sk_SK`, `en_US`) |
| `title` | required, up to 160 characters |
| `summary` | optional, up to 500 characters |
| `body` | Markdown, rendered with the active theme prefix |
| `starts_at` | required, site-local `Y-m-d H:i:s` |
| `ends_at` | optional, never before `starts_at` |
| `venue` | optional |
| `address` | optional |
| `capacity` | whole number greater than zero |
| `reservations_enabled` | whether the public form is open |
| `published` | whether the detail is public |

### `event_reservations`

A row carries the event, the party size, the guest's name and e-mail, optional
phone and note, the consent timestamp and the decision state. The IP address is
kept for rate limiting and is never rendered.

## Reservation states

- `pending` holds the party's seats.
- `confirmed` keeps the hold and changes what the guest was told.
- `cancelled` releases the seats.

Capacity is `capacity - SUM(party_size)` over pending and confirmed rows. There
is no counter column. The sum and the insert run in one write transaction
(`BEGIN IMMEDIATE` on SQLite), so two visitors cannot claim the same last seat.
A cancelled reservation cannot be confirmed again; the guest submits a new
request.

## Public surfaces

- `GET /events` lists published events that start now or later, in the request
  locale, ordered by start then title.
- `GET /events/{slug}` renders a published event, including a past one. A draft
  is a styled 404. A past event shows no reservation form.
- `POST /events/{slug}/reservations` validates the request, checks the honeypot
  and the per-IP window, then stores one pending row.

A successful request redirects to
`/events/{slug}?reservation-sent=1#reserve`. The `reservation-sent` marker is in
`PageCacheService::BYPASS_QUERY_PARAMS`, so the private confirmation is never
written to the shared cache. A validation or capacity refusal re-renders the
detail with the old input and no `Location` header.

## Mark surfaces

| path | purpose |
|---|---|
| `GET /mark/events` | events for the selected locale |
| `GET|POST /mark/events/create` | create one event |
| `GET|POST /mark/events/{slug}/edit` | edit an event |
| `POST /mark/events/{slug}/publish` | publish or unpublish |
| `POST /mark/events/{slug}/delete` | delete an event with no reservations |
| `GET /mark/events/{slug}/reservations` | the organizer inbox |
| `POST /mark/events/{slug}/reservations/{id}/confirm` | confirm one request |
| `POST /mark/events/{slug}/reservations/{id}/cancel` | cancel one request |

Every write is audited and invalidates only `/events`. A delete is refused while
any reservation row exists. The nav badge counts pending rows with `COUNT(*)`.

`/mark/events` is in `MarkRoutePolicy` under `Permissions::CONTENT`.

## Mail

Mail is best-effort and sent after the database transaction commits. A failed
transport never rolls back a stored reservation.

- The organizer alert goes through `MarkNotifier`, so the recipient is the
  `admin_email` setting.
- The guest acknowledgement, confirmation and cancellation go through
  `MailerFactory`.
- A failure logs one bounded `[events]` line with the reservation id and mail
  kind. The guest's address and message body never enter the log.

In development the dev log mailer writes messages to `storage/logs/app.log`.

## The `/events` page collision

The literal `/events` route wins over the Pages `/{slug}` catch-all. A client
that already publishes a page with the slug `events` must remove that page or
move it to another slug when the module is installed. The module cannot serve
both surfaces at one URL.

## Operator steps

1. Install the module:

   ```sh
   php bin/cms module:add Events
   php bin/cms migrate
   ```

2. Open `/mark/events`, create an event in the site language, then publish it.
3. Open `/{locale-prefix}/events` and confirm the event is listed.
4. Submit a reservation from the detail page.
5. Open the organizer inbox, confirm the request, and read the guest message
   and the operator message in `storage/logs/app.log` during development.
6. Point the site menu at `/events`, if the menu does not already carry it.

## Removing the module

Unpublish or remove the events first, then:

```sh
php bin/cms module:remove Events
```

The module tool removes code and leaves `events.db` in place. Restore the
client's former `events` page from its seed if one existed.
