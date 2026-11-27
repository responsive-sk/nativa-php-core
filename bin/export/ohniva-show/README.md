# Nativa Ohniva

**This tree is generated.** Every file here is exported from
[`responsive-sk/nativa-php-core`](https://github.com/responsive-sk/nativa-php-core)
at a recorded commit; `.nativa-ohniva-show-export.json` names it. A hand edit
here is overwritten by the next export. Change the source repository instead.

## What it is

The ohniva-show.sk site, as a runnable client site:

- The kernel slices `Modules\Core`, `Modules\Database`, `Modules\Tooling` and
  `Modules\KernelTheme`, plus the kernel's own presentation in
  `src/Modules/Core/Interfaces/HTTP/Theme`. SEO lives in `Modules\Core`.
- The site module set: `Audit`, `Auth`, `Blog`, `Cardboard`,
  `ContentLocale`, `Forms`, `Locales`, `Markdown`, `Media`, `Navigation`,
  `Notifications`, `Ohniva`, `Outbox`, `PageCache`, `Pages`, `Pagination`,
  `Queue`, `Security`, `Settings`, `Uploads`, `Users` and `Validation`.
- Ohniva is the public theme. Cardboard is the `/mark` panel.
- A content seed under `storage/pages`, `storage/blog` and
  `storage/blog-images`: 14 pages, 5 blog posts and 81 local images copied
  from the live site on 2026-11-27. The seed belongs to this client; it is not
  the responsive.sk archive.
- Installed slices under `src/Modules/`. The catalogue under `modules/` is the
  source the slices were copied from; the two are identical when the export is
  published, and `php bin/cms module:show` reports the drift.

The boundary rule is one sentence: a kernel file may not import a module, and a
module may import the kernel. `make mago-guard` fails on the other direction.

## Run it

```sh
php bin/cms migrate
php bin/cms db:import config/seed --force
php bin/cms settings:profile
php -S 127.0.0.1:8000 -t public
```

`migrate` seeds the kernel defaults. `db:import` writes the site's header and
footer menus and the contact form from the portable snapshot in
`config/seed`. `settings:profile` applies `config/deploy.php` once, so a fresh
clone boots as `ohniva-show.sk` with Ohniva and the `Europe/Bratislava`
timezone. Do not run `db:import --force` or `settings:profile` on an update:
they would overwrite the menu, form and settings an operator changed in
`/mark`.

The header menu is Domov, Info, Blog, Akcie, Časté otázky, Galéria and Kontakt.
The footer repeats Info, Akcie, Blog and Časté otázky. Service pages are linked
from Info and the homepage; they are not a second dropdown.

`vendor/` is not needed. The runtime has zero Composer packages: `src/init.php`
installs a PSR-4 fallback loader, and `composer install` is development tooling
only. `/mark` redirects to `/login`; create the first administrator with
`php bin/cms user:operator`.

The first migrate builds the SQLite files under `storage/data/`. They are
runtime state and are never committed.

## Content and uploads

The repository carries a content seed and a portable database seed, not the
live database. `config/seed` is the one-time menu and form bootstrap: a small
NDJSON snapshot with a manifest, imported by `db:import`. `storage/` on a
server is where the site lives: `storage/pages/`, `storage/blog/`,
`storage/blog-images/`, `storage/uploads/` and one database file per module
under `storage/data/`. An update replaces the code and never `storage/`.

Page and article images use `/storage/blog-images/...`, so they are served by
the Blog module's read-only route and survive a deployment without a separate
upload step. Replacing an image means replacing the file and keeping its name;
the route answers with a long cache lifetime.

Uploads are written by the running site and are not in this repository. A
deployment snapshots them before an update and restores them after a rollback.

## Build the theme

```sh
make assets     # pnpm workspace, public theme bundle
```

`make assets` needs pnpm and network access for the first install. A deployment
does not: the theme's critical CSS and script travel in the module and
`public/dist/moonshine` is committed for the panel.

## Gates

```sh
make gates                # every pin and gate, from toolchain.json
make lint                 # every PHP file parses
composer phpstan          # types over the kernel scope
make test                 # migrate, then PHPUnit: tests/ plus modules/*/tests/
make mago-guard           # the kernel-to-module dependency rule
make mago-lint            # curated lint rules, ceiling zero
make assets               # the public theme bundle builds and resolves
make dist-check           # a clean clone builds a green artifact
make security-audit       # no tracked .env, no secret-shaped literal
make dependency-audit     # no high or critical advisory in either lockfile
```

`make test` migrates first because the slices are installed: the module tests
query real tables instead of skipping for a missing schema.

## License

MIT. See `LICENSE`.
