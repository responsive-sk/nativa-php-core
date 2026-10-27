> ACTIVE — migrated 2026-10-14 from `.ai-factory/plans/feature-marko-adoption.md`
> (which is retired). Status: Phases A–E DONE (`modules/Validation/`,
> `modules/Scheduler/` + `modules/Queue/`, `modules/Health/`, selective page-cache
> invalidation, `translation-file`, `notification-database`), canonical spec at
> `openspec/specs/validation/`, deltas in `specs/health/`,
> `specs/page-cache-invalidation/`, `specs/translation-file/` and
> `specs/notification-inbox/`. Phases F–G OPEN, tracked in `tasks.md`.

# Marko adoption plan (zdroj: ../marko klon, 2026-10-13)

## Why

Marko má hotové balíčky, ktoré tento strom potrebuje (validácia, scheduler/queue,
health, page-cache, preklady, notifikácie, pagination), ale nedajú sa pridať ako
závislosť: každý ťahá `marko/core` a rozbil by zero-dependency `dist/`. Bez portu
si každý modul drží vlastnú kópiu alebo funkcia chýba úplne.

## What Changes

- **Fáza A (DONE)** — `modules/Validation/`: validátor, `RuleParser`,
  `ValidationErrors`, ~19 pravidiel, všetkých 11 call-sites, kanonický spec
  `openspec/specs/validation/spec.md`.
- **Fáza B (DONE)** — `modules/Scheduler/` + `modules/Queue/` (`jobs`,
  `failed_jobs`, `DatabaseQueue`, `HandlerRegistry`) zjednotené s `Outbox`;
  príkazy `schedule:run`, `queue:work`, `queue:prune`, `outbox:process`.
- **Fáza C (DONE)** — `modules/Health/` + `GET|HEAD /health` s tromi probami
  (modulové SQLite, `storage/cache`, `public/dist` manifesty), zapojené do
  `build-dist.sh` smoke testu; delta spec `specs/health/spec.md`.
- **Fáza D (DONE)** — selektívna invalidácia page cache: `PageCacheService`
  ukladá request path k záznamu a `invalidatePaths()` maže len stránky pod
  daným prefixom (`/blog`, `/`, `/authors`); blogové zápisy a outbox publish
  handler prestali volať `CacheInvalidator::flush()`. Delta spec
  `specs/page-cache-invalidation/spec.md`.
- **Fáza E1 (DONE)** — `translation-file`: JSON loader ako skutočný zdroj
  v reťazi čítania (cache → riadok → `storage/locales/<locale>.json` → default
  → kľúč), export riadkov do súboru cez `POST /mark/locales/export`, kľúče len
  zo súboru viditeľné v paneli s príznakom `file` a bez edit-linku. Delta spec
  `specs/translation-file/spec.md`.
- **Fáza E2 (DONE)** — `notification-database`: `modules/Notifications/` s
  vlastnou SQLite DB, jeden riadok na príjemcu s jeho vlastným read-state
  (`forNotifiable`/`unreadCount`/`markRead`/`markAllAsRead`), zápis riadku pred
  odoslaním mailu, `GET /mark/notifications` v paneli a badge s počtom
  neprečítaných v sidebar-i. Delta spec `specs/notification-inbox/spec.md`.
- **Fázy F–G (OPEN)** — `pagination` (offset + cursor), RFC plugin hookov.
  Detail a poradie v `tasks.md`.

## Impact

- Affected specs: `validation` (base), `health` a `page-cache-invalidation`
  (delty), neskôr `scheduler`, `queue`, `pagination`.
- Code: nové moduly v `modules/` + inštalované slice v `src/Modules/`, príkazy v
  `bin/cms`, smoke v `bin/build/build-dist.sh`.
- Bez zmeny runtime závislostí: žiadny `marko/*` v `composer.json`, `dist/`
  zostáva bez `vendor/`.

## 1. Kontext a obmedzenia

- `nativa-php-core`: PHP ^8.5, zero-dependency runtime (`dist/` bez `vendor/`,
  `src/init.php` PSR-4 fallback), SQLite (`storage/data/*.db`), moduly
  `modules/<Name>/` -> `src/Modules/<Name>/` cez `bin/cms`, atributová
  discovery (`#[Route]`, `#[AsMigrator]`, `#[AsMiddleware]` …), Vite vanilla témy.
- `marko/marko` (klon v `../marko`, ~90 balíčkov v `packages/`): PHP ^8.5,
  každý balíček `type: marko-module` + `require: marko/core` (+ príp. `marko/cli`,
  `marko/config`, `marko/routing`, `marko/database`, `marko/cache` …).
- Dôsledok: **žiadny `composer require marko/*` do runtime** — zatiahol by
  `marko/core` + strom a rozbil `dist/` claim (overené: `validation`,
  `scheduler`, `health`, `queue-database`, `page-cache-entity` všetky vyžadujú
  `marko/core`). Stratégia je **port do `Modules\*` v plain PHP + SQLite**,
  nie závislosť. Výnimka: `testing` vzory len pre dev.

## 2. Čo Marko má (podľa `../marko/packages/`)

- Jadro: `core` (Container, PreferenceDiscovery/Registry, `#[Preference]`,
  `#[Plugin]` + `#[Before]`/`#[After]`, `#[Observer]`, Command discovery),
  `config`, `env`, `routing`, `cli`, `framework`.
- Data/cache: `database(-pgsql/-mysql/-readwrite)`, `cache(-array/-file/-redis)`,
  `page-cache(-file)`, `page-cache-entity`, `session(-file/-database)`.
- Infra: `mail(-log/-smtp)`, `queue(-sync/-database/-rabbitmq)`, `log(-file)`,
  `filesystem(-local/-s3)`, `encryption(-openssl)`, `media(-gd/-imagick)`,
  `http(-guzzle)`, `clock`.
- Web: `view(-latte/-twig)`, `layout`, `errors(-simple/-advanced)`, `cors`,
  `ratelimiter` (atribút `#[RateLimit]` + middleware, IPv6-safe kľúče,
  `Retry-After`), `health` (registry + `/health` endpoint), `api`, `pagination`
  (offset + cursor, `toArray()` s meta/links), `webhook` (HMAC-SHA256, retry,
  replay-guard), `search`, `vite`, `inertia(-react/-svelte/-vue)`.
- Auth: `authentication`, `authentication-token` (scoped PAT,
  `TokenManager::createToken`, plaintext len pri vytvorení),
  `authorization` (`#[Can]` + Gate + PolicyRegistry + middleware),
  `hashing`, `security`.
- Realtime: `pubsub(-pgsql/-redis)`, `broadcasting(-amphp/-mercure/-pusher)`,
  `sse`, `amphp`, `roadrunner`.
- Obsah: `translation(-file)`, `notification(-database)`, `scheduler`
  (fluent API + cron výrazy + `FileTaskMutex` + `schedule:run`/`schedule:work`),
  `queue-database` (`jobs` + `failed_jobs`, transakčný polling),
  `validation` (28 pravidiel, `required|string|max:100`, wildcards `photos.*`),
  `docs(-markdown/-fts)`.
- Admin: `admin`, `admin-auth`, `admin-api`, `admin-panel(-latte/-twig)`.
- Dev/AI: `devserver`, `debugbar`, `devai`, `mcp`, `lsp`, `codeindexer`,
  `claude-plugins`, `testing` (fakes: Auth, Broadcaster, Clock, Config,
  Encryptor, EventDispatcher… + HTTP test client + Pest base), `skeleton`.

## 3. Tier 1 — portovať (sedí k SQLite + zero-dep)

### 3.1 Validation (`../marko/packages/validation/src/`)
- Rozsah: `Contracts/{ValidatorInterface,RuleInterface}`, ~28 `Rules/*.php`
  (Required, Email, Integer, Min/Max, Between, Confirmed, Same/Different,
  Nullable, ArrayType, File/Image/Mimes, Url, Regex, In/NotIn…), string-DSL
  (`'required|email'`, `'photos.*' => 'image|max_size:2048'`).
- U nás nahradí ručné validácie (`AuthService::register`,
  `UsersAction::validate`, Cardboard formuláre).
- Port ako `modules/Validation/` (Contracts + Rules + `Validator`,
  bez `marko/core`; file-pravidlá nad naším `MediaUploadPayload`).
- Akceptácia: `Validator::validate($input, $rules)` vracia chyby;
  pokryté `AuthService` + jeden Cardboard formulár; `composer test` zelený.

### 3.2 Scheduler + queue-database
- `scheduler/src`: `Schedule`, `ScheduledTask`, `CronExpression`,
  `ScheduleRunner`, `Mutex/FileTaskMutex`, `Command/RunScheduleCommand.php`
  (`schedule:run` exit 1 pri chybe, `schedule:work`).
- `queue-database/src`: `DatabaseQueue`, `DatabaseFailedJobRepository`,
  entity `DatabaseJob`/`DatabaseFailedJob` (`jobs`, `failed_jobs`).
- U nás: zjednotiť s `Outbox` (`outbox:process`), pridať `bin/cms schedule:run`
  (plain-PHP vetva vedľa `migrate`, nie symfony/console).
- Akceptácia: `FileTaskMutex::withoutOverlapping` funguje na file-locku;
  failed job zostáva v `failed_jobs`; cron z `storage/` configu.

### 3.3 Health (`../marko/packages/health/src/`)
- `Contracts/HealthCheckInterface`, `Value/{HealthResult,HealthStatus}`,
  `Registry/HealthCheckRegistry`, `Checks/{Database,Filesystem,Cache}HealthCheck`,
  `Controller/HealthController` (`/health`).
- Port ako `modules/Health/` s checkmi nad našimi SQLite DB + `public/dist`
  manifest + zapojiť do `bin/build/build-dist.sh` smoke-testu.
- Akceptácia: `/health` → 200 JSON `{status, checks[]}`; failing check → 503.

### 3.4 page-cache-entity
- Bridge „entity save/delete → purge tagov" nad `page-cache`.
- U nás: `BlogCrudService`/Pages/Media po zápise volajú selektívny purge
  namiesto `CacheInvalidator::flush()` všetkého.
- Akceptácia: edit článku purge-uje len `/blog*` + `/`, ostatné cache žije.

### 3.5 translation-file + notification-database
- `translation-file/src/Loader/FileTranslationLoader.php` (`lang/<locale>/<group>.php`)
  ako alternatíva k DB `SeedData`; `notification-database` (repository,
  `forNotifiable`/`unreadCount`/`markAllAsRead`) ako inbox v `/mark`.
- Akceptácia: file-loader fallback pri chýbajúcom DB riadku; notifikácie
  per-user s read-state v SQLite.

### 3.6 pagination (+ api základ, + authentication-token neskôr)
- `pagination/src`: `OffsetPaginator`, `CursorPaginator`, `toArray()`
  (items/meta/links) bez väzby na data-layer.
- U nás: tabuľky (`UsersTableRenderer`, články, komentáre) + budúce API.
  Token-auth (`TokenManager`, scoped PAT) až s API modulom, nie skôr.
- Akceptácia: jeden zoznam v `/mark` stránkuje cez spoločný paginátor.

## 4. Tier 2 — inšpirácia (myšlienka, nie kód)

- **Preference/Plugin/Observer** (`core/src/Attributes/`, `Container/`,
  `Plugin/`, `Observer/`): naše `ThemeRegistry`, `MiddlewareRegistry`,
  `ShellContextProvider`, `AdminRoutePolicy`, `class_exists`-guardy robia to
  isté horšie. RFC: jeden `#[Plugin]`-hook okolo `ModuleInstaller::add/remove`
  a Cardboard CRUD; `#[Preference]` ako náhrada glob-order hlasovania tém.
- **Interface/impl split + manager**: Marko to má dôsledne
  (cache/mail/queue/session/filesystem). U nás čiastočne
  (`DatabaseConnectionManager`, `LocalStorageProvider`). Pravidlo pre nové
  moduly: `Contracts/` + manager, file/DB driver default.
- **ratelimiter vzor**: my máme `RateLimiter` nad `users.db`; Marko pridáva
  `#[RateLimit]` atribút + `Retry-After` + IPv6-safe kľúče — prevziať atribút
  a hlavičky, nie driver (je cache-backed, my sme DB-backed).
- **authorization `#[Can]` + Gate**: naša `AdminRoutePolicy` je tabuľka
  prefix→permission; `#[Can]` na akciách by ju urobilo explicitnou.
- **errors-advanced / debugbar**: len za `APP_DEBUG`, nikdy do `dist/`.
- **docs-fts**: FTS5 nad docs/blog v SQLite, žiadna externá search služba.
- **testing fakes**: `FakeClock`, `FakeEventDispatcher`, `FakeGuard`,
  `TestDatabase`/`RefreshDatabase` ako vzor pre naše PHPUnit (máme PHPUnit 12,
  nie Pest — nepreberať runner, len fake-pattern).

## 5. Tier 3 — nebrať

- Externé služby/drivers: `database-pgsql/mysql`, `cache-redis`,
  `pubsub-*`, `queue-rabbitmq`, `filesystem-s3`, `broadcasting-*`,
  `http-guzzle`, `mail-smtp` (rozbije shared-hosting + zero-dep).
- Cudzie UI vrstvy: `view-latte/twig`, `inertia-*`, `admin-panel-*`
  (rozbije Vite vanilla témy + freeze-nuté bundly + `Cardboard`).
- Async servery: `roadrunner`, `amphp` (deploy je `php -S`/FTP/rsync).
- AI balíky (`mcp`, `lsp`, `devai`, `codeindexer`, `claude-plugins`): až po
  stabilizácii Tier 1. `skeleton`/`MarkoTalk` len ako referenčná appka.

## 6. Postup

1. Fáza A: `modules/Validation/` (najmenšie, okamžitý úžitok).
2. Fáza B: `modules/Scheduler/` + `schedule:run` v `bin/cms`, zjednotenie
   s `Outbox`.
3. Fáza C: `modules/Health/` + `/health` + smoke-test v `build-dist.sh`.
4. Fáza D: selektívna invalidácia (page-cache-entity vzor).
5. Fáza E: RFC plugin-hook (do `docs/kernel-status.md`), nie kód.
6. Každá fáza: port bez `marko/*` závislostí, `composer test` + `phpstan`
   zelené, `module:add/remove` round-trip (hash pred/po identický).
