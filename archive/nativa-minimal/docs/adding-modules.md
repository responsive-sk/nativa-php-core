# Adding Modules

The starter kit is a modular monolith: each feature lives in its own bounded context under `src/Modules/<Module>/` with a vertical-slice layout. This guide shows how to create a new module from scratch — the same recipe you use to port feature modules from the full Nativa CMS.

## Module skeleton

```
src/Modules/<Module>/
├── Domain/                     # entities, value objects, repository interfaces
├── Application/
│   └── Services/               # use cases / services
├── Infrastructure/
│   └── Persistence/
│       ├── Migrations/         # #[AsMigrator] schema builders
│       └── Repositories/       # PDO-backed repositories
└── Interfaces/
    └── HTTP/
        ├── Actions/            # #[Route] handlers (auto-discovered)
        └── <Module>Services.php  # lazy service factories (optional)
```

Namespaces mirror the folders: `Modules\<Module>\Domain\...`, `Modules\<Module>\Application\...`, etc. Everything autoloads via `src/init.php`'s PSR-4 loader (no `composer dump-autoload` needed after adding files).

## 1. Domain entity (optional)

```php
<?php

declare(strict_types = 1);

namespace Modules\Books\Domain\Entities;

final readonly class Book
{
    public function __construct(private array $data)
    {
    }

    public function id(): string
    {
        return (string) $this->data['id'];
    }

    public function title(): string
    {
        return (string) $this->data['title'];
    }
}
```

Entities are thin data wrappers over DB rows — no framework base classes.

## 2. Migration

Every module that owns a database provides a migrator. Create
`src/Modules/<Module>/Infrastructure/Persistence/Migrations/<Module>DatabaseMigrator.php`:

```php
<?php

declare(strict_types = 1);

namespace Modules\Books\Infrastructure\Persistence\Migrations;

use Modules\Core\Infrastructure\Attributes\AsMigrator;
use Modules\Core\Interfaces\CLI\CliOutput;
use Modules\Core\Interfaces\CLI\MigratorInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsMigrator(module: 'books', table: 'books')]
final readonly class BooksDatabaseMigrator implements MigratorInterface
{
    public function __construct(private \PDO $pdo)
    {
    }

    public function run(CliOutput | OutputInterface $output): void
    {
        $output->writeln('<info>Migrating books.db...</info>');

        // Idempotent — safe to re-run via `bin/cms migrate`.
        $this->pdo->exec(<<<'SQL'
                CREATE TABLE IF NOT EXISTS books (
                    id VARCHAR(32) PRIMARY KEY,
                    title VARCHAR(255) NOT NULL,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                )
            SQL);
    }
}
```

The `module` name in `#[AsMigrator]` maps to the SQLite file: `storage/data/books.db`. The migrator receives that connection via `DatabaseConnectionManager`. **No manual registration needed** — `DatabaseMigrator` discovers all `#[AsMigrator]` classes automatically.

## 3. Repository

```php
<?php

declare(strict_types = 1);

namespace Modules\Books\Infrastructure\Persistence\Repositories;

use Modules\Books\Domain\Entities\Book;

final class BookRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    public function findById(string $id): ?Book
    {
        $stmt = $this->pdo->prepare('SELECT * FROM books WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        return false === $row ? null : new Book($row);
    }
}
```

Reuse the connection through the singleton manager in your action/services layer:

```php
$pdo = \Modules\Database\Infrastructure\Persistence\DatabaseConnectionManager::instance()->connection('books');
```

## 4. Route action

Routes are attribute-based and **auto-discovered** by the `Router`, which scans `src/Modules` for classes with `#[Route]` (same as the Kernel does at boot). No central route table to update.

```php
<?php

declare(strict_types = 1);

namespace Modules\Books\Interfaces\HTTP\Actions;

use Modules\Core\Interfaces\HTTP\Attributes\Route;
use Modules\Books\Infrastructure\Persistence\Repositories\BookRepository;

#[Route(path: '/books', method: 'GET', name: 'books.index')]
final class BooksIndexAction
{
    public function __invoke(): string
    {
        $repo = new BookRepository(
            \Modules\Database\Infrastructure\Persistence\DatabaseConnectionManager::instance()->connection('books'),
        );

        // Return HTML — or a full page via FrontendLayout::render().
        return \Modules\Core\Interfaces\HTTP\FrontendLayout::render('Books', '<h1>Books</h1>');
    }
}
```

Multiple routes per class use `handlerMethod`:

```php
#[Route(path: '/books/create', method: 'GET', name: 'books.create', handlerMethod: 'createForm')]
#[Route(path: '/books/create', method: 'POST', name: 'books.create.submit', handlerMethod: 'createSubmit')]
final class BookCreateAction
{
    public function createForm(): string { /* ... */ }
    public function createSubmit(): string { /* ... */ }
}
```

### Route attributes

| Argument | Description |
|---|---|
| `path` | URL pattern, e.g. `/books/{id}` — `{id}` captured into route params |
| `method` | `GET` \| `POST` \| `PUT` \| `DELETE` \| `PATCH` |
| `name` | Stable name for `Router::url('books.index')` generation |
| `handlerMethod` | Which class method handles the route (default `__invoke`) |

## 5. Protecting admin routes

The `AuthMiddleware` protects any path starting with `/mark`. For a new admin area under the same guard, prefix your routes with `/mark/...`. The middleware requires the `admin` role (403 otherwise) and redirects unauthenticated visitors to `/login`.

If your module needs its own role/permission, seed it — see `bin/cms seed` (`SeedCommand`) and `RoleRepository::ensurePermission()`.

## 6. Lazy service wiring (optional)

Actions are instantiated by the Kernel **without dependency injection**, so share heavy services through a module-level factory class (the `CardboardServices` / `AuthServices` pattern). Connections are cached by `DatabaseConnectionManager`, so factories are cheap:

```php
final class BookServices
{
    private static ?BookRepository $books = null;

    public static function books(): BookRepository
    {
        return self::$books ??= new BookRepository(
            \Modules\Database\Infrastructure\Persistence\DatabaseConnectionManager::instance()->connection('books'),
        );
    }
}
```

## 7. Localized file content (optional)

If your module stores markdown (or any file) **per locale**, resolve the
directory through `ContentServices::mapper()` — never a literal, and never a
`string $locale = 'sk_SK'` parameter default:

```php
use Modules\Core\Interfaces\HTTP\ContentServices;

$dir = ContentServices::mapper()->directory('books', $locale);  // 'books' or 'books/en'
$prefix = ContentServices::mapper()->urlPrefix($locale);        // '' or '/en'
```

The rule: the configured default locale (`settings.locale`) owns the family
root, every other locale gets a `{lang}` subdirectory and a `/{lang}` URL
prefix. Hardcoding either shape is what let Blog and Pages drift apart from
the canonical spec in the first place; `ContentLocaleLayoutTest` fails the
build if you do it.

Two things to know before you rely on it:

- An unknown locale code resolves to the configured default, never to
  Slovak — a typo must not quietly serve the wrong language.
- Changing `settings.locale` remaps which directory is the root, and **the
  files do not move with it**. If your module ships content, a locale switch
  needs a migration step alongside it.

## 8. Embedding a feature in markdown content (optional)

If your module produces something a page or an article should be able to
EMBED — a form, a poll, a callout, a download list — do not add a route to
every renderer and import your module from Core, Pages and Blog. Declare a
markdown block instead; the Core registry discovers it by attribute.

```php
namespace Modules\Books\Application\Markdown;

use Modules\Core\Infrastructure\Attributes\AsMarkdownBlock;
use Modules\Core\Interfaces\HTTP\Markdown\MarkdownBlockContext;
use Modules\Core\Interfaces\HTTP\Markdown\MarkdownBlockInterface;

#[AsMarkdownBlock(module: 'books', priority: 100)]
final class BookShortcodeBlock implements MarkdownBlockInterface
{
    public function name(): string
    {
        return 'book';
    }

    public function expand(string $html, MarkdownBlockContext $context): string
    {
        // $context->locale is the visitor's locale;
        // $context->cssPrefix is the ACTIVE THEME'S PREFIX (`st`/`er`/`ms`/`mag`,
        // no trailing dash — the same value ThemeModule::prefix() returns).
        // Build every class from it, so the block renders themed in all four
        // wired themes without a single theme-side change.
    }
}
```

Placement: `Application/Markdown/` or `Interfaces/HTTP/Markdown/` under your
module. `MarkdownBlockRegistry::all()` directory-scans both, sorts by
priority, and `MarkdownRenderer::render()` runs every block over the built
HTML — **after** markdown, so your block sees real HTML.

Two rules that cost real bugs if forgotten:

- **Split on `<pre>` before you match.** A shortcode inside a fenced code
  block is documentation of the syntax, not a request — expanding it would
  delete the very example the page exists to show.
- **Return `''` for anything that resolves to nothing.** A typo or a draft
  must leave no trace; a raw `{{…}}` in the visitor's face is worse than an
  absent widget.

`Modules\Forms\FormShortcodeBlock` is the worked example — see
`docs/forms.md`.

## 9. Run it

```bash
php bin/cms migrate     # creates books.db
php bin/cms serve       # /books now resolves
```

## Porting checklist (from the full Nativa CMS)

1. **Copy the module folder** into `src/Modules/` — keep `Domain/`, `Application/`, `Infrastructure/`, `Interfaces/` structure.
2. **Strip framework dependencies** — the starter has zero runtime framework; replace framework ORM/query-builder calls with plain PDO + repositories.
3. **Rewrite migrations** as `#[AsMigrator]` classes with idempotent `CREATE TABLE IF NOT EXISTS` (no separate migration runner/tables).
4. **Route localized paths through the mapper** — see §7. Replace any `'en_US' === $locale ? … : 'sk'` with `ContentServices::mapper()`.
4. **Rewrite routes** as `#[Route]` attributes (Nativa's route names/paths map 1:1).
5. **Replace templates** with `FrontendLayout::render()` + inline/self-hosted assets — no CDNs, no `@layer` CSS.
6. **Wire services** through a module `*Services` factory.
7. **Add tests** — a `:memory:` SQLite repository test in `tests/Integration/` and route assertions.
8. **Verify gates**: `make pre-commit` (phpstan level 9, cs-fixer, rector, phpunit) and `make build` (`verify-build.php`).
