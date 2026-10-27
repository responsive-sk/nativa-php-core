# Marko adoption — tasks

Phases A–F are done; G is open. Each phase: port without `marko/*`
dependencies, `composer test` + `phpstan` green, `module:add/remove`
round-trip (hash identical before/after).

- [x] **Phase A: `modules/Validation/`** — DONE. Validator, RuleParser,
  ValidationErrors, ~19 rules, all 11 call-sites ported with byte-identical
  messages, canonical spec at `openspec/specs/validation/spec.md`.
- [x] **Phase B: `modules/Scheduler/` + `schedule:run`** — DONE, archived as
  `openspec/changes/archive/2026-10-16-scheduler-queue`. Ported
  `Schedule`/`ScheduledTask`/`CronExpression`/`ScheduleRunner`/`FileTaskMutex`
  and `queue-database` (`jobs` + `failed_jobs`, `DatabaseQueue`,
  `HandlerRegistry`); unified with `Outbox` (`outbox:drain` every 5 min in the
  shipped timetable); plain-PHP `bin/cms schedule:run` branch, plus the admin
  queue/outbox/schedule pages.
- [x] **Phase C: `modules/Health/` + `/health`** — DONE. Ported
  `HealthCheckInterface`/`HealthResult`/`HealthStatus`/registry plus three
  probes: module SQLite files (`database`), the writable `storage/cache`
  directory (`filesystem`), and `public/dist/<theme>/manifest.json` bundles
  (`dist`) — a separate cache probe was not needed, the cache is a filesystem
  target. 200 for `healthy`/`degraded`, 503 for `unhealthy`, `no-store`, GET
  and HEAD; wired into the `build-dist.sh` smoke test and pinned by the
  capability delta at `specs/health/spec.md`.
- [x] **Phase D: selective invalidation** — DONE. `PageCacheService::set()`
  records the request path beside the entry and `invalidatePaths()` deletes the
  entries under a segment-boundary prefix (`/blog` covers `/blog/{slug}`, never
  `/blogfeed`; `/` is the homepage only); `CacheInvalidator::invalidate()` is
  the static entry point, `flush()` stays for writes that cannot name paths.
  Blog create/edit/delete/publish/inline/tag/category writes and the outbox
  publish handler invalidate `/`, `/blog`, `/authors` instead of flushing.
  Delta spec: `specs/page-cache-invalidation/spec.md`; pinned by
  `tests/Cache/PageCacheInvalidationTest.php`.
- [x] **Phase E1: `translation-file`** — DONE. The JSON loader is a real
  source in the read chain, not a branch nobody takes: cache → `translations`
  row → `storage/locales/<locale>.json` → caller default → raw key, read once
  per request, with the database winning where both carry a key.
  `POST /mark/locales/export` writes a locale's rows to its file (audited, no
  page-cache flush, no file at all for a locale without rows), which is what
  keeps the fallback maintainable instead of hand-edited. The admin list shows
  file-only keys marked `file` with no id and no edit link — editing one in
  place would create a row that shadows the file — and a key the file provides
  stops being reported as missing. Delta spec:
  `specs/translation-file/spec.md`; pinned by
  `modules/Locales/tests/TranslationFileFallbackTest.php`.
- [x] **Phase E2: `notification-database`** — DONE. `modules/Notifications/`
  owns `storage/data/notifications.db`: one row per recipient (so one moderator
  reading a notification does not clear another's badge), with `forNotifiable`,
  `unreadCount`, `markRead` and `markAllAsRead` scoped on the notifiable in the
  SQL itself. The `notify.admin` consumer in `Users` writes a row for every
  active account that can open the panel before it attempts the mail, and an
  inbox write that fails is logged rather than allowed to cost the
  notification. `GET /mark/notifications` (Cardboard, under `mark.access`)
  lists the signed-in user's inbox and offers per-row and whole-inbox read
  actions; the sidebar item carries the unread count via `#[AsNavBadge]`. The
  seeded admin nav row, the policy row and the nav copy were updated together,
  and the copy's permission columns now use the `Permissions` constants instead
  of re-spelt literals (which returned 10 places of headroom to the literal
  ceiling). Delta spec: `specs/notification-inbox/spec.md`; pinned by
  `modules/Notifications/tests/`, `modules/Users/tests/AdminInboxWiringTest.php`
  and `modules/Cardboard/tests/NotificationsPageTest.php`.
- [x] **Phase F: `pagination` (+ api/token-auth later)** — DONE. `modules/Pagination/`
  ships the two shapes behind one `Paginator` contract: `OffsetPaginator`
  (page numbers over `LIMIT`/`OFFSET`, with the requested page clamped to the
  range that exists so `?page=99999` reads the last page instead of an empty
  table, and `from()`/`to()` answering 0–0 for an empty list) and
  `CursorPaginator` (keyset pages from a `limit + 1` fetch — the extra row is
  dropped from the page and kept as "there is more", because a full page
  without it cannot invent a cursor). Both answer `items`/`meta`/`links`
  through `toArray()`, whose closure takes the page VALUE and nothing else: the
  paginator is transport-free and never spells a query key, so the panel and a
  future API address pages in their own words. `GET /mark/notifications` is the
  one `/mark` list paged through it (count first, offset from the clamped page,
  page one bare and `?page=N` after it, a range line plus inert ends in the
  pager, and no pager over a single page). Delta spec: `specs/pagination/spec.md`;
  pinned by `modules/Pagination/tests/` and
  `modules/Cardboard/tests/NotificationsPageTest.php`. Scoped PAT tokens only
  with the API module.
- [ ] **Phase G (docs only): plugin-hook RFC** — `#[Plugin]` hook around
  `ModuleInstaller::add/remove` and Cardboard CRUD into
  `docs/kernel-status.md`, no code.
