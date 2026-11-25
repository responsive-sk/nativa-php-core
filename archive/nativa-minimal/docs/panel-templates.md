# Admin & Auth Templates (ViewRenderer)

Since the `2026-09-21-extract-theme-critical-css` change, admin, auth and
debug surfaces are **PHP include templates** rendered through the Core
`View` helpers, not PHP heredocs inside action classes.

## The View helpers (`Modules\Core\Application\Services\View`)

| Helper | Purpose |
|--------|---------|
| `ViewRenderer::render(string $path, array $vars = []): string` | Output-buffered template include. Variables are extracted with `EXTR_SKIP`; a per-request cache avoids double reads; a guard throws when a template is rendered directly (must go through `ViewRenderer`). |
| `InlineCss::file(string $path): string` | Raw bytes of a CSS asset under `src/` (critical-CSS concatenation). |
| `InlineCss::files(list<string> $paths): string` | Ordered concatenation for the critical-CSS section lists. |
| `InlineJs::file(string $path): string` | Raw bytes of a `.js` asset — the body of an inline `<script>` tag. |

All paths are module-relative (`Modules/Cardboard/...`,
`Themes/moonshine/...`) and resolved by the shared `Paths` helper.

## Template home

Templates live next to their owning module's HTTP layer:
`src/Modules/*/Interfaces/HTTP/templates/*.phtml`.

```
src/Modules/Cardboard/Interfaces/HTTP/
├── AdminLayout.php            # shell — renders templates/layout.phtml
├── CompactLayout.php          # renders templates/compact.phtml
├── templates/                 # layout.phtml, compact.phtml, dashboard*.phtml,
│                              # bulk-actions.phtml, settings-tabs.phtml, …
└── assets/scripts/*.js        # inline <script> bodies (admin-shell.js, …)
```

Every template starts with the `ViewRenderer` guard (enforced by
`tests/Architecture/TemplateHygieneTest.php`):

```php
<?php
declare(strict_types = 1);

if (!isset($viewRendererGuard)) {
    throw new \RuntimeException('Template must be rendered via ViewRenderer.');
}
```

## Why templates live inside their modules

A central `views/` or `templates/` directory would be the classic Laravel-era
layout. This kit deliberately rejects it — module-owned templates are the
consequence of three architectural rules:

1. **Vertical slices.** A module is a self-contained feature cut: actions,
   layouts, templates, inline script assets and migrations of one feature sit
   side by side under `Modules/<Feature>/Interfaces/HTTP/`. Working on the
   media admin means touching one directory; deleting the Media module takes
   its templates with it, nothing orphaned elsewhere.
2. **Module isolation.** A shared template directory is a shared write
   surface — every module reaching into one folder is coupling by another
   name (plus merge conflicts). The architecture suite
   (`tests/Architecture/ModuleIsolationTest.php`) pushes the other way: a
   module owns everything it renders. Only genuinely shared pieces live in
   the shared kernel (`Core`): the `AdminLayout`/`CompactLayout` shells,
   `ViewRenderer`, `InlineCss`/`InlineJs`. Modules compose them; they do not
   share markup files between modules.
3. **Enforceable hygiene.** The guard clause, strict typing and the
   presentation-only rules below hold only because the location convention
   is fixed: `TemplateHygieneTest` can enumerate
   `Modules/*/Interfaces/HTTP/templates/*.phtml` and assert all of them.
   Templates scattered across arbitrary paths could not be audited as one
   set.

The same logic applies to the theme renderers (`src/Themes/*/Sections/` —
see `docs/theme-layout.md`): markup always lives with the component that
owns it, never in a project-wide bucket.

## Hygiene rules (enforced by `TemplateHygieneTest`)

Templates are presentation only — they must not contain:

- SQL or query execution,
- service-locator calls (`Container::get`, `->make()`, `->resolve()`),
- `header(` or `session_*` calls,

and they must declare `strict_types`.

## Script wiring

- **Static script bodies** live in `assets/scripts/*.js` and are injected
  via `InlineJs::file()` into nonce-carrying `<script>` tags that stay in
  the PHP/template markup.
- **Dynamic values** (e.g. `var recent = <?php echo $recentJson; ?>;`)
  are interpolated by the template directly — never baked into a static
  asset.
- **Deliberate inline exceptions:** the FOUC-guard theme-init script in
  `FrontendLayout` stays inline in the layout heredoc (it must run
  synchronously before first paint and is part of the layout contract).

## Where to add things

| What | Where |
|------|-------|
| New admin page markup | a `.phtml` template under the owning module's `Interfaces/HTTP/templates/` |
| New inline script body | `assets/scripts/*.js` + `InlineJs::file()` at the tag site |
| New critical CSS section | `assets/critical/*.css` under the theme/module + the ordered list in the loader (`InlineCss::files()`) |

Parity contracts: `tests/Integration/CriticalCssByteParityTest.php` (CSS)
and `tests/Integration/InlineScriptByteParityTest.php` (JS bodies).
