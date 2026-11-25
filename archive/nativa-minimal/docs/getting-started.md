# Getting Started

Nativa Minimal is a from-scratch PHP 8.5 starter kit: zero-dependency runtime, `#[Route]` attribute routing, SQLite per-module databases, auth + RBAC, and an admin panel. This guide walks through installing, running, and building it.

## Requirements

| Tool | Version |
|---|---|
| PHP | 8.5+ with `ext-pdo_sqlite`, `ext-sqlite3`, `ext-mbstring`, `ext-json` |
| Composer | 2.x (dev tooling only) |
| Node.js + pnpm | 20+ (frontend build, optional for runtime) |

No runtime Composer packages are required — the application boots from `src/init.php` with a zero-dependency PSR-4 autoloader. Composer is used only for dev tooling (PHPUnit, PHPStan, Rector, PHP-CS-Fixer, Codeception, Symfony Console).

## 1. Install

```bash
composer install
```

Installs dev tooling into `vendor/`. Frontend packages (Vite + TypeScript) install on first `make build` via pnpm, or now:

```bash
cd Frontend/packages && pnpm install
```

## 2. Configure environment

```bash
cp .env.example .env
```

| Variable | Default | Purpose |
|---|---|---|
| `APP_ENV` | `development` | `development` \| `production` |
| `APP_DEBUG` | `true` | Detailed error pages (dev only) |
| `APP_TIMEZONE` | `Europe/Bratislava` | PHP timezone |
| `SESSION_NAME` | `nativa_starter` | Session cookie name |
| `SESSION_LIFETIME` | `1209600` | Session TTL (seconds) |
| `SESSION_SECURE` | `false` | `true` in production behind HTTPS |
| `SEED_ADMIN_PASSWORD` | `admin123` | Seed password (change after first login) |
| `LOG_LEVEL` | `debug` | `debug` \| `info` \| `warn` \| `error` |

## 3. Migrate

```bash
php bin/cms migrate
# or
composer migrate
# or
make migrate
```

Creates the per-module SQLite databases in `storage/data/` (idempotent — safe to re-run):

- `users.db` — users, roles, permissions, pivots, sessions, rate_limits, login_attempts
- `config.db` — settings, menu_items, design_tokens
- `locales.db` — locales, translations
- `audit.db` — immutable admin audit log
- `forms.db` — form definitions, submissions and answers

## 4. Seed demo data

```bash
php bin/cms seed
# with a custom password:
php bin/cms seed --admin-password='strong-password'
```

Seeds the `admin` role with 5 permissions plus the default admin user.

### Demo forms (optional)

```bash
php bin/cms forms:seed
```

Creates four demo form definitions (Slovak and English contact form, a poll,
a quiz) and the two scratch pages that embed them, so `/contact`, `/anketa`
and `/test` are worth opening. It is its own command, not a branch in
`seed`: the seed command lives in Core, and Core must not import a feature
module. See `docs/forms.md`.

### Default admin login

After `php bin/cms seed` (without arguments):

| | |
|---|---|
| **Email** | `admin@example.test` |
| **Password** | `admin1234` |

> ⚠️ **Change the password after first login.** The default is for local development only — use `--admin-password` in any real environment.

### Default author login

The seed also creates the `author` role (`mark.access` + `content.manage` — no
access to users, roles or settings) and one loginable account that owns most of
the articles in `storage/blog/`:

| | |
|---|---|
| **Email** | `nativa.dev@example.test` |
| **Password** | `author1234` |

Under that role the sidebar shows Articles, Pages, Comments, Media and Forms,
and the admin-only areas (Users, Roles, Permissions, Settings, Locales, Audit)
are **403, not hidden only in the nav** — requesting one directly returns
`Forbidden — the users.manage permission is required`.

Articles are additionally gated on ownership: the author sees and edits only
the articles whose `author_email` resolves to them, and editing, publishing or
deleting someone else's article is a 403 rather than a redirect. Pages stay
group-editable by anyone holding `content.manage` — pages carry no per-page
owner.

The second seeded byline (`evan.mcdan@example.test`) is a display profile with a
random password, so it cannot be used to sign in. Override the login account
with `--author-email` / `--author-password`.

## 5. Run the dev server

```bash
php bin/cms serve        # http://localhost:8000
# or
composer serve
# or
make dev
```

Open http://localhost:8000 — you should see the homepage with hero + feature cards. Admin panel: http://localhost:8000/mark (redirects to `/login` when unauthenticated).

### Read the repository's own guides at `/docs`

The guides that live in `docs/` are readable in the browser: http://localhost:8000/docs. The blog's own articles link them — 159 links to `/docs/getting-started` alone — so this is the route those links were always pointing at. Three things about it are deliberate:

- **A whitelist, not a directory.** 16 documents are published — 13 guides plus the architecture decision records under `docs/adr/` (e.g. `/docs/adr/0003-theme-module-contract`), which link each other and the guides. The security audits, the PR summary, the Lekalo trace manifests and the dex reference code are not, even though they sit in the same directory. Adding a document is one entry in `DocsCatalog`.
- **`noindex`.** The pages carry `noindex` and stay out of `/sitemap.xml` and `robots.txt`. They are developer-facing, and the links into them come from articles that already rank.
- **No page cache.** An edited guide is visible on the next reload instead of up to five minutes later. A deployment that ships no `docs/` answers a normal 404 inside the site layout rather than failing, and a content page slugged `docs` would be shadowed by the route (none exists).

## 6. Build the frontend

Every theme ships as its own Vite + TypeScript package under `Frontend/packages/<theme>/` (starter, erik, moonshine, magazine, minimal), targeting **ES2015 / Chrome 70+**. Each builds to `public/dist/<theme>/` with its own manifest. Build them all when you change any `src/main.ts` or `src/base.css`:

```bash
make build               # pnpm run build + bin/build/verify-build.php
# or step by step:
cd Frontend/packages && pnpm run build
php bin/build/verify-build.php
```

`bin/build/verify-build.php` checks the built CSS has **no `@layer`**, contains **`@supports` gap fallbacks**, the JS is **ES2015** (no `?.`/`??`), and all assets are **self-hosted**. The homepage layout resolves hashed asset URLs from `public/dist/starter/manifest.json` via `AssetManager`; without a build it falls back to an inline critical style so pages never render unstyled.

### Responsive navigation (mobile)

Both the public header and the admin panel (`/mark`) ship a **CSS-only mobile nav** — zero JavaScript: below 768px the nav links collapse behind a hamburger button (a visually hidden checkbox toggled by a `<label>`) that opens a full-width, scrollable overlay panel under the header; tapping outside closes it. It reuses the DB-driven menu, so items added in `/mark/settings` appear in the mobile menu too, and nested dropdown groups render as an indented list on phones.

## 7. Database reset (dev)

```bash
make db-reset            # drops *.db + markers, re-migrates, re-seeds
# or
composer db-reset
```

## 8. Quality gates

```bash
make analyze             # phpstan (level 9) + rector dry + cs-fixer dry
make test                # PHPUnit + (with server running) Codeception acceptance
make pre-commit          # lint + analyze + test — run before committing
```

| Tool | Config | Scope |
|---|---|---|
| PHPStan | `phpstan.neon` | level 9, whole `src/` |
| Rector | `rector.php` | php83 sets, level 0 |
| PHP-CS-Fixer | `.php-cs-fixer.dist.php` | PSR-12 + quality rules |
| PHPUnit | `phpunit.xml` | unit + integration + css suites |
| Codeception | `codeception.yml` | acceptance smoke (`tests/Acceptance/SmokeCest.php`) |

Codeception acceptance tests need the dev server running on port 8000:

```bash
php bin/cms serve &
vendor/bin/codecept run Acceptance
```

## 9. Production build (dist)

The production artifact lives in `dist/` — a zero-dependency copy of the application with freshly migrated + seeded databases, ready to deploy. No `vendor/` is shipped: `src/init.php` boots the app with the built-in PSR-4 autoloader.

### Build

```bash
make dist
```

`make dist` runs the full pipeline (equivalent to `./bin/build/build-dist.sh`):

1. **Frontend build** — Vite (ES2015 target) + `bin/build/verify-build.php` (old-browser baseline).
2. **Application code** — `src/`, `config/`, `bin/`, `docs/`, `.ai-factory/` copied verbatim.
3. **Public web root** — `public/index.php`, `public/.htaccess` and the Vite bundle (`public/dist/`, real file copies — no symlinks, FTP-friendly).
4. **Fresh databases** — `bin/build/build-install.php` runs migrate + seed in an isolated scratch directory (`DB_*` env overrides; your local `storage/data/` is never touched) and ships self-contained `users.db` + `config.db`.
5. **Storage skeleton + `.build-info`** (build time, version, commit) + hardened permissions.

### What is excluded

- `vendor/` — dev tooling (Composer, symfony/console) never ships; the zero-dep autoloader covers everything.
- `src/Modules/*/Interfaces/CLI/Command/` — dev-only console commands (`migrate`, `seed`, `serve`) that extend `symfony/console`. The web runtime auto-migrates on first request via `Bootstrapper`.
- `bin/cms`, `bin/dev/db-reset.php` — symfony-based dev CLI scripts.
- Your local `storage/data/` SQLite files.

### Default admin (dist)

The seeded admin password is set at build time via `BUILD_ADMIN_PASSWORD` (the build script ships `demo`):

| | |
|---|---|
| **Email** | `admin@example.test` |
| **Password** | `demo` |

> ⚠️ Override `BUILD_ADMIN_PASSWORD` for real deployments and change the password after first login.

### Production environment

Set these in production (see `.env.example`):

- `APP_ENV=production`, `APP_DEBUG=false` — generic error pages, `/debug` returns 404, HSTS enabled over HTTPS.
- `TRUSTED_HOSTS=your.domain` — comma-separated public hostnames. Guards the `Host` header used for
  absolute URLs (canonical/hreflang/sitemap/feeds) against poisoning; a mismatching `Host` fails
  closed to the first entry. Empty = any Host accepted (dev default).
- `TRUSTED_PROXIES=` — only when the app sits behind a reverse proxy that sets `X-Forwarded-For`;
  list the proxy IPs/CIDRs so rate limiting still sees the real client IP.
- `SESSION_SECURE=true` when TLS terminates outside PHP (auto-detected from HTTPS otherwise).

### Deploy

```bash
rsync -az --delete dist/ user@host:/var/www/nativa-minimal/
# or upload the dist/ contents via FTP/FileZilla — all files are real copies
```

### Build options

| Flag | Effect |
|---|---|
| `--skip-assets` | Skip the Vite build (`./bin/build/build-dist.sh --skip-assets`) — use when assets are already built |
| `--with-env` | Copy `.env.example` as `.env` template (`./bin/build/build-dist.sh --with-env`) |
| `--help` | Show usage |

### Verify the dist

```bash
php -S 127.0.0.1:8000 -t dist/public &
make acceptance ACCEPTANCE_URL=http://127.0.0.1:8000   # E2E: homepage, /mark redirect, login with CSRF
```

The acceptance suite (`tests/Acceptance/{LoginFlowCest,SmokeCest}.php`) verifies the full login flow with CSRF, the seeded credentials, and each theme's own rendered vocabulary. `make acceptance` supplies the base URL (`ACCEPTANCE_URL`, default `http://127.0.0.1:8000`) and the admin password (`ADMIN_PASSWORD`) — against the dev server use the default seed password `admin1234`, against a dist build use the one that build was seeded with. The same target runs in CI as the `acceptance` job.

## 9.3 Docker (dev + prod)

The kit ships a multi-stage `Dockerfile` plus Compose files. The production image is **zero-dependency** — no `vendor/`, no Composer: `src/init.php` boots the app with its built-in PSR-4 autoloader, exactly like the `dist/` artifact. It is built on `php:8.5-apache` (mod_php) with `public/` as the document root and `AllowOverride All`, so the shipped `.htaccess` (rewrite, security headers, caching, compression) keeps working. The Vite frontend bundle is built in a separate `node:24-alpine` stage (pnpm, frozen lockfile).

### Requirements

- Docker + Docker Compose **v2.24+** (the dev override uses `healthcheck: !reset`).
- `.env` copied from `.env.example` (optional for dev — sensible defaults apply).

### Dev workflow

```bash
make docker-up          # docker compose up -d --build
# open http://localhost:8000
```

The dev target runs the hardened built-in dev server (`php -S 0.0.0.0:8000 -t public bin/dev/dev-server-router.php`) and **bind-mounts the project source**, so PHP edits apply instantly — no rebuild. `public/dist/` is masked with an anonymous volume so the image's built frontend stays available without a host Vite build; run `make build` on the host (or rebuild the image) when you change TS/CSS sources.

### Production workflow

```bash
make docker-prod-up     # docker compose -f compose.yml -f compose.production.yml up -d --build
# open http://localhost:8080
```

The production overlay hardens the container: **read-only rootfs** (Apache runtime dirs on `tmpfs`), `no-new-privileges`, `cap_drop: [ALL]` with only Apache's needed caps, CPU/memory/PID limits, log rotation, and `APP_ENV=production` / `APP_DEBUG=false`. There is no database container — the app uses per-module SQLite and **auto-migrates on first request**, so an empty `storage/data` volume is all it needs. TLS termination is left to your host / external proxy (out of scope).

### Volumes

Runtime-only storage is persisted on named volumes and survives container recreation: `storage-data`, `storage-uploads`, `storage-logs`, `storage-cache`, `storage-sessions`, `storage-blog-images`. Committed blog content (`storage/blog/`) stays in the image.

## 9.5 Uploads & author profiles

Stored uploads live in `storage/uploads/` (date-based, random hex filenames) and are served read-only at `/storage/uploads/{path}` — dotfiles, `..` traversal and non-whitelisted MIME types return 404 (whitelist: jpg/png/gif/webp/pdf/txt/md). User avatars are uploaded in `/mark/users`; when an article's `author_email` matches an active user, the blog renders the user's avatar + a link to their public profile at `/authors/{slug}` (404 for unknown/inactive users). The slug is the diacritics-folded display name (same `MarkdownRenderer::slugify` convention as tags/categories), so renaming a user changes their public URL.

## 10. Project layout

```
public/index.php              # HTTP entry — Kernel + Router dispatch
src/init.php                  # zero-dependency bootstrap (autoload, env, errors, session)
src/Modules/
├── Core/                     # bootstrap, env, HTTP kernel, router, middleware, assets
├── Database/                 # SQLite connection factory + migrator runner
├── Security/                 # password hashing, rate limiting
├── Users/                    # auth, sessions, RBAC, password reset
├── Settings/                 # site settings, design tokens, navigation
└── Cardboard/                # admin panel (dashboard, user/role CRUD, settings)
Frontend/packages/          # pnpm workspace root — one package per theme (ES2015)
storage/                      # data/ (SQLite), uploads/, logs/, sessions/, cache/
tests/                        # PHPUnit (unit/integration/css) + Codeception acceptance
```

## Next steps

- Port feature modules from the full Nativa CMS → [docs/adding-modules.md](adding-modules.md)
- Architecture overview → `README.md` in the repository root, and
  [docs/template-map.md](template-map.md) for the screen → renderer map
