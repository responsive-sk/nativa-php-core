---
title: "Auto-Discovery Metrics: When Every Module Owns Its Dashboard"
date: "2026-09-03"
version: "1.0.0"
tags: ["php", "architecture", "cardboard", "metrics", "auto-discovery"]
excerpt: "Each module registers its own aggregated stat cards into the dashboard. No central service pulls data from everywhere. The same auto-discovery pattern as middleware, just an attribute and a glob."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architecture"
views: "0"
translation: "auto-discovery-metrics"
published: true
---

The dashboard showed seven stat cards. Users, roles, permissions, media, articles, pages, runtime. Every card was hardcoded in `DashboardAction`: six private methods, each querying a different service, each building a `Card` object.

It worked. But every new module meant editing `DashboardAction`. Every metric calculation lived in the wrong place. The admin panel knew about blog views, comment counts, and media uploads. The modules themselves knew nothing about their own dashboard presence.

## The problem with centralized metrics

Here is what `DashboardAction::registerContentCards()` looked like:

```php
private function registerContentCards(): void
{
    $mediaCount = MediaServices::media()->count();
    $crud = new BlogCrudService();
    $articleSk = count($crud->list('sk_SK'));
    $articleEn = count($crud->list('en_US'));
    $pageSvc = new PageCrudService();
    $pageSk = count($pageSvc->list('sk_SK'));
    $pageEn = count($pageSvc->list('en_US'));

    CardboardRegistry::register(
        Card::make('media', 'Media files', $mediaCount)
            ->withActions(
                CardLink::make('List', '/mark/media'),
                CardLink::make('Upload', '/mark/media/upload'),
            )
            ->withPriority(70),
    );
    // ... more cards
}
```

Three problems here.

**First**, `DashboardAction` imports `MediaServices`, `BlogCrudService`, `PageCrudService`. The admin panel knows the internals of every module. That is a dependency inversion violation, the orchestrator depends on the details.

**Second**, adding a new module's metrics means editing `DashboardAction`. Add a `CommentsMetric`: edit the file. Add an `AuditMetric`: edit the file again. The module cannot be self-contained if its dashboard presence requires external wiring.

**Third**, there is no time period selection. The cards show total counts. No trend arrows, no comparison with last week, no "are we growing or shrinking?"

## The existing pattern: attribute auto-discovery

Nativa Minimal already has two auto-discovery mechanisms:

**Middleware.** Classes annotated with `#[AsMiddleware(priority: N)]` are found by `MiddlewareRegistry`. It scans `src/Modules/*/Interfaces/HTTP/Middleware/*.php`, checks for the attribute via reflection, instantiates classes with no required constructor parameters.

```php
#[AsMiddleware(priority: 0)]
final class SecurityHeadersMiddleware implements MiddlewareInterface
{
    // No constructor dependencies — resolved internally
}
```

**Migrators.** Classes annotated with `#[AsMigrator(module: 'users', table: 'sessions')]` are found by `DatabaseMigrator`. Same pattern, scan, reflect, instantiate.

Both follow the same rule: **the module is self-contained**. Adding middleware means creating a file with the attribute. Removing it means deleting the file. Zero edits to `public/index.php`.

The question was: can metrics follow the same pattern?

## The contract: MetricContract

A metric provider is a class that knows how to register its own stat cards into `CardboardRegistry`. The interface is minimal:

```php
interface MetricContract
{
    /**
     * Register this module's metric cards into CardboardRegistry.
     */
    public function registerCards(Period $period): void;
}
```

One method. One parameter. The `Period` enum tells the provider what time range to compute trends for:

```php
enum Period: string
{
    case SEVEN_DAYS = '7d';
    case THIRTY_DAYS = '30d';
    case NINETY_DAYS = '90d';

    public function sqlInterval(): string
    {
        return match ($this) {
            self::SEVEN_DAYS  => '-7 days',
            self::THIRTY_DAYS => '-30 days',
            self::NINETY_DAYS => '-90 days',
        };
    }

    public function previousSqlInterval(): string
    {
        return match ($this) {
            self::SEVEN_DAYS  => '-14 days',
            self::THIRTY_DAYS => '-60 days',
            self::NINETY_DAYS => '-180 days',
        };
    }
}
```

`previousSqlInterval()` returns the interval for the prior period of the same length. This lets each provider compute a trend: "this period vs. the same-length period before it."

## The attribute: #[AsMetricProvider]

The attribute mirrors `#[AsMiddleware]`:

```php
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class AsMetricProvider
{
    public function __construct(
        public int $priority = 100,
    ) {
    }
}
```

Priority controls display order. Higher values render first. Users at 100, Blog at 90, Audit at 60.

## What a module registers

Each module creates a `*Metric.php` class in its `Application/Services/` directory. Here is `UsersMetric`:

```php
#[AsMetricProvider(priority: 100)]
final readonly class UsersMetric implements MetricContract
{
    public function registerCards(Period $period): void
    {
        try {
            $pdo = DatabaseConnectionManager::instance()->connection('users');

            // Total users
            $total = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();

            // New users in period
            $newInPeriod = (int) $pdo->prepare(
                'SELECT COUNT(*) FROM users WHERE created_at >= datetime("now", :interval)'
            )->execute([$period->sqlInterval()])->fetchColumn();

            // Previous period (for trend)
            $prevInterval = $period->previousSqlInterval();
            $newPreviously = (int) $pdo->prepare(
                'SELECT COUNT(*) FROM users WHERE created_at >= datetime(:prev) AND created_at < datetime("now", :curr)'
            )->execute([$prevInterval, $period->sqlInterval()])->fetchColumn();

            $trend = Trend::make($newInPeriod, $newPreviously);

            CardboardRegistry::register(
                Card::make('users', 'Users', $total)
                    ->withSubtitle($trend->render() . ' in ' . $period->label())
                    ->withActions(
                        CardLink::make('List', '/mark/users'),
                        CardLink::make('New', '/mark/users/create'),
                    )
                    ->withPriority(100),
            );
        } catch (\Throwable) {
            // Database not ready yet — skip silently
        }
    }
}
```

Notice two things:

**The module owns its data access.** No central service reaches into `users.db`. The `UsersMetric` class lives in `Modules\Users` and queries its own database directly.

**Graceful degradation.** If `users.db` does not exist yet (first boot, migration not run), the `catch (\Throwable)` block means the card simply does not appear. No error, no crash.

## Trend: the arrow that tells the story

The `Trend` value object computes period-over-period comparison:

```php
final readonly class Trend
{
    public function __construct(
        public int $current,
        public int $previous,
    ) {
    }

    public static function make(int $current, int $previous): self
    {
        return new self(current: $current, previous: $previous);
    }

    public function percentage(): float
    {
        if (0 === $this->previous) {
            return 0.0;
        }
        return round((($this->current - $this->previous) / $this->previous) * 100, 1);
    }

    public function arrow(): string
    {
        return match ($this->direction()) {
            'up'   => '↑',
            'down' => '↓',
            default => '—',
        };
    }

    public function render(): string
    {
        $dir = $this->direction();
        $pct = $this->percentage();
        $sign = $pct > 0 ? '+' : '';
        $class = "m-trend m-trend--{$dir}";

        return '<span class="' . $class . '">'
            . $this->arrow() . ' ' . $sign . $pct . '%'
            . '</span>';
    }
}
```

The `render()` method produces BEM-styled HTML:

```html
<span class="m-trend m-trend--up">↑ +12.5%</span>
<span class="m-trend m-trend--down">↓ −3.2%</span>
<span class="m-trend m-trend--flat">— 0%</span>
```

This is a presentation-layer value object. It lives in `Cardboard\Domain\Model` because it is a card concern, every metric provider uses it, but only to format output for the dashboard.

## MetricRegistry: the auto-discovery engine

`MetricRegistry` scans for `#[AsMetricProvider]` classes using the same pattern as `MiddlewareRegistry`:

```php
final class MetricRegistry
{
    /** @var list<MetricContract>|null */
    private static ?array $metrics = null;

    public static function all(): array
    {
        if (null !== self::$metrics) {
            return self::$metrics;
        }

        $metrics = [];
        $base = AppPaths::instance()->src('Modules');
        $dirs = glob($base . '/*/Application/Services/*Metric.php');

        if (!is_array($dirs)) {
            self::$metrics = [];
            return [];
        }

        foreach ($dirs as $file) {
            self::scanFile($file, $metrics);
        }

        usort($metrics, static fn (array $a, array $b): int => $b[0] <=> $a[0]);
        self::$metrics = array_values(array_map(
            static fn (array $e): MetricContract => $e[1],
            $metrics,
        ));

        return self::$metrics;
    }

    private static function scanFile(string $file, array &$metrics): void
    {
        // Parse namespace + class from file content
        // Check for #[AsMetricProvider] attribute via reflection
        // Instantiate (no required constructor params)
        // Sort by priority
    }
}
```

The glob pattern `src/Modules/*/Application/Services/*Metric.php` finds all metric classes across all modules. The registry caches the result, first call discovers, subsequent calls return the cached list.

**The key rule:** metric classes MUST have no required constructor parameters. Dependencies are resolved internally via lazy factories (`DatabaseConnectionManager::instance()->connection('users')`). This is the same constraint as middleware.

## DashboardAction: what changes

The old `DashboardAction` had six private methods for card registration. The new one has one:

```php
private function registerCards(Request $request): void
{
    CardboardRegistry::reset();
    $period = Period::tryFrom((string) $request->query('period', '7d'))
        ?? Period::SEVEN_DAYS;

    foreach (MetricRegistry::all() as $metric) {
        $metric->registerCards($period);
    }
}
```

Three lines of logic. The period comes from the URL (`?period=30d`). Each metric provider registers its own cards. The dashboard does not know what metrics exist.

The period selector is generated by `DashboardAction`: a simple set of links:

```html
<div class="m-period-selector">
    <a href="?period=7d" class="m-period-btn m-period-btn--active">7 days</a>
    <a href="?period=30d" class="m-period-btn">30 days</a>
    <a href="?period=90d" class="m-period-btn">90 days</a>
</div>
```

Existing query parameters are preserved,`buildPeriodLinks()` reads `Request::getQueryParams()`, replaces only the `period` key.

## The five modules

| Module | Cards | Data Source |
|--------|-------|-------------|
| Users | Users (total + trend), Sessions (active + trend) | `users.db` → users, sessions |
| Blog | Article Views (trend + sparkline), Articles (SK/EN donut) | `blog.db` → article_views, filesystem |
| Comments | Comments (by status donut + trend) | `comments.db` → comments |
| Media | Media (total + uploads trend) | `media.db` → assets |
| Audit | Audit Actions (trend + top actions bar chart) | `audit.db` → audit_log |

Each module is an independent vertical slice. `BlogMetric` lives in `Modules\Blog\Application\Services`. It queries `blog.db` and counts articles from the filesystem. It never touches `users.db` or `comments.db`.

## Adding a new module

To add metrics for a hypothetical `Analytics` module:

1. Create `src/Modules/Analytics/Application/Services/AnalyticsMetric.php`
2. Annotate with `#[AsMetricProvider(priority: 50)]`
3. Implement `MetricContract::registerCards(Period $period)`
4. Done

No edits to `DashboardAction`. No edits to `public/index.php`. No edits to any other file. The `MetricRegistry` discovers the new class on next request.

To remove the module: delete the file. The registry no longer finds it.

This is the same pattern as adding middleware. Create a file with `#[AsMiddleware]`: it appears in the pipeline. Delete it, it disappears. The module is self-contained.

## C-Pattern compliance

The metric system follows all Nativa Minimal architecture rules:

- **No magic methods**: `Trend` and `Period` are plain value objects
- **No Reflection outside `Modules\Core`**: `MetricRegistry` lives in `Cardboard\Infrastructure\Registry`, but the reflection logic mirrors `MiddlewareRegistry` in `Core` (acceptable for shared infrastructure)
- **No `eval()`**: all code is explicit
- **No service locators**: metric classes resolve their own dependencies via `DatabaseConnectionManager::instance()`
- **Methods under 60 lines**: each `registerCards()` method is focused and short
- **No speculative interfaces**: `MetricContract` has 5 implementations (Users, Blog, Comments, Media, Audit)
- **Domain isolation**: `MetricContract` lives in `Cardboard\Domain\Contracts`; module metrics live in their own `Application/Services` layer

## The result

A dashboard where:

- Each module owns its metrics
- Period selection works across all cards (7d / 30d / 90d)
- Trend arrows show growth or decline at a glance
- Adding a module's metrics requires one file, one attribute
- Zero central coordination
- Zero dependency injection framework
- Zero Composer packages in production

The entire metric system is 5 new files (Trend, Period, MetricContract, AsMetricProvider, MetricRegistry) plus 5 module metric classes. Each module metric class is under 60 lines.

---

→ [MoonShine Cardboard](/blog/moonshine-cardboard): how MoonShine UI inspired the admin panel design
→ [Middleware Pipeline](/blog/middleware-pipeline): the auto-discovery pattern for middleware
→ [Module Isolation](/blog/module-isolation): why modules must not know about each other

## Sources

- [PHP Manual](https://www.php.net/manual/): official PHP documentation
- [PHP-FIG, PSR Standards](https://www.php-fig.org/): PHP standards
- [OWASP Cheat Sheet Series](https://cheatsheetseries.owasp.org/): security best practices
- [Nativa Minimal Documentation](/docs/getting-started): official getting started guide
- [W3C, Web Standards](https://www.w3.org/standards/): web standards
