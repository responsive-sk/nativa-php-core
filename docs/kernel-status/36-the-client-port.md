# Boot 71: the private client port

`responsive-sk/nativa-resp` is the second generated tree.
It is the responsive.sk site as a runnable repository: the 28-module closure,
the Boson theme, the tracked content seed and the installed slices.
It is private, and it is replaced by the next publish rather than edited by hand.

## What the profile carries

The set is Audit, Auth, Blog, Boson, Cardboard, Comments, ContentLocale, Docs,
Forms, Health, Locales, Markdown, Media, Navigation, Notifications, Outbox,
PageCache, Pages, Pagination, Questions, Queue, RestApi, Security, Settings,
Site, Uploads, Users and Validation.
`KernelTheme` is the twenty-ninth installed slice; it is the kernel's own
presentation and has no catalogue entry.

The profile keeps `storage/pages`, `storage/blog` and `storage/blog-images`.
It prunes the development apparatus: `docs`, `openspec`, `lekalo`, `themes`,
`fixtures`, `public/demo`, the source-only build scripts, the themes the site
does not render and the frontend packages it does not build.

`bin/export/resp/profile.json` owns the facts.
The module set is an allow-list, so a module added to the catalogue and not
named here is pruned by default.
A named module that is absent fails the export by name.
`bin/export/resp/prune.txt` names the non-module paths, one reason per path.

## Measured export

The export from `f2b700df` carries 1600 files.
`module:show` reports 28 identical, 0 drifted, 0 not installed and 1 not
catalogued; the one is `KernelTheme`.
The content seed is 4 pages, 17 articles and 3 images.
The exported suite discovers 1183 tests, 4238 assertions and 37 skips.
The source suite is 1605 tests, 9782 assertions and 19 skips.
The core export is 1030 tests.

The core profile did not move.
Its exported file list and its suite stay the same apart from the profile
contract test, which skips in a tree that prunes `bin/export`.

## The boot the first draft got wrong

`php bin/cms migrate` seeds the kernel defaults.
The first measured boot answered with the kernel title, not `responsive.sk`.
The missing step was `settings:profile`, which is how
`bin/build/build-dist.sh` applies `config/deploy.php` after `migrate`.
The exporter now runs it after `migrate` in `--check`, and the exported README
tells a fresh clone to run it once.
The title then begins with `responsive.sk`, and the profile also sets Boson,
the site description and the Bratislava timezone.

The measured surfaces are `/`, `/blog`, `/docs`, `/health`, `/robots.txt` and
`/sitemap.xml` at 200.
`/mark` answers 302 to `/login`, and `/login` answers 200.
The server log has no fatal error.
The application log carries migration lines, not a failed request.

## The first private run

The first private run is 45096268850 on `master`.
It completed as a failure in four seconds.
All eight jobs have zero steps and zero billable milliseconds.
The annotation says the job was not started because recent account payments
failed or the spending limit needed to be increased.
Actions is enabled for the repository, so the workflow is not the cause.
The organisation's billing limit stopped the runner before any step.
No green private run is measured yet.

The local gate set is green: lint, PHPStan, 1605 source tests, the style and
Rector dry runs, the 1030-test core export and the 1183-test client export.

## The two copies

The client tree tracks both `modules/` and `src/Modules/`.
The catalogue keeps `module:list`, `module:remove` and the panel's Modules page
working; the installed slices make a clone boot Boson without a setup step.
The exporter installs the catalogue in closure order and checks `module:show`
after removing the excluded tests, so the two copies cannot drift silently.
A hand edit in the client tree is replaced by the next publish.

## Still open

- The organisation's Actions spending limit, so the first green run is not
  measured.
- Uploads are runtime state and never travel in the export.
- The content is a seed; a client edit in the generated repository is lost on
  the next publish.
