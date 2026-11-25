---
title: "Auto-Discovery Metrík: Keď Každý Modul Vlastní Svoj Dashboard"
date: "2026-09-03"
version: "1.0.2"
tags: ["php", "architecture", "cardboard", "metrics", "auto-discovery"]
excerpt: "Každý modul si registruje vlastné agregované stats cards do dashboardu. Žiadna centrálna služba neťahá dáta zo všetkého. Rovnaký auto-discovery pattern ako middleware, len atribút a glob."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architektúra"
views: "0"
translation: "auto-discovery-metrics"
published: true
---

Dashboard ukazoval sedem stat kariet. Users, roles, permissions, media, articles, pages, runtime. Každá karta bola hardcoded v `DashboardAction`: šesť private metód, každá sa pýtala iného servisu, každá budovala `Card` objekt.

Fungovalo to. Ale každý nový modul znamenal editovať `DashboardAction`. Každý výpočet metriky žil na zlom mieste. Admin panel vedel o blog views, commentoch a media uploadoch. Moduly samé nevedeli o svojej dashboard prítomnosti.

## Problém s centrálnymi metrikami

Takto vyzeralo `DashboardAction::registerContentCards()`:

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
    // ... viac kariet
}
```

Tri problémy.

**Prvý**, `DashboardAction` importuje `MediaServices`, `BlogCrudService`, `PageCrudService`. Admin panel pozná interné detaily každého modulu. To je dependency inversion violation, orchestrátor závisí na detailoch.

**Druhý**, pridanie metriky nového modulu znamená editovať `DashboardAction`. Pridáš `CommentsMetric`: edituješ súbor. Pridáš `AuditMetric`: edituješ znova. Modul nemôže byť self-contained, ak jeho dashboard prítomnosť vyžaduje externú wiring.

**Tretí**, nie je časový výber. Karty ukazujú totálne počty. Žiadne trend šípky, žiadne porovnanie s minulým týždňom, žiadne „rastieme alebo klesáme?“

## Existujúci pattern: atribút auto-discovery

Nativa Minimal už má dva auto-discovery mechanizmy:

**Middleware.** Triedy s atribútom `#[AsMiddleware(priority: N)]` nájde `MiddlewareRegistry`. Skenuje `src/Modules/*/Interfaces/HTTP/Middleware/*.php`, kontroluje atribút cez reflexiu, inštanciuje triedy bez povinných constructor parametrov.

```php
#[AsMiddleware(priority: 0)]
final class SecurityHeadersMiddleware implements MiddlewareInterface
{
    // Žiadne constructor závislosti — rieši interne
}
```

**Migratory.** Triedy s atribútom `#[AsMigrator(module: 'users', table: 'sessions')]` nájde `DatabaseMigrator`. Rovnaký pattern, scan, reflect, instantiate.

Oba dodržiavajú rovnaké pravidlo: **modul je self-contained**. Pridanie middleware znamená vytvoriť súbor s atribútom. Odobranie znamená vymazať súbor. Žiadne editácie v `public/index.php`.

Otázka znela: môžu metriky nasledovať rovnaký pattern?

## Zmluva: MetricContract

Metric provider je trieda, ktorá vie, ako si registrovať vlastné stat cards do `CardboardRegistry`. Rozhranie je minimálne:

```php
interface MetricContract
{
    /**
     * Registruj modulove metric cards do CardboardRegistry.
     */
    public function registerCards(Period $period): void;
}
```

Jedna metóda. Jeden parameter. `Period` enum hovorí provideru, aký časový rozsah má použiť pre výpočet trendov:

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

`previousSqlInterval()` vracia interval pre predchádzajúce obdobie rovnakej dĺžky. Toto umožňuje každému provideru vypočítať trend: „toto obdobie vs. rovnako dlhé obdobie pred ním.“

## Atribút: #[AsMetricProvider]

Atribút zrkadlí `#[AsMiddleware]`:

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

Priority určuje poradie zobrazenia. Vyššie hodnoty sa renderujú prvé. Users na 100, Blog na 90, Audit na 60.

## Čo modul registruje

Každý modul vytvorí `*Metric.php` triedu vo svojom `Application/Services/` adresári. Tu je `UsersMetric`:

```php
#[AsMetricProvider(priority: 100)]
final readonly class UsersMetric implements MetricContract
{
    public function registerCards(Period $period): void
    {
        try {
            $pdo = DatabaseConnectionManager::instance()->connection('users');

            // Celkový počet userov
            $total = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();

            // Noví useri v období
            $newInPeriod = (int) $pdo->prepare(
                'SELECT COUNT(*) FROM users WHERE created_at >= datetime("now", :interval)'
            )->execute([$period->sqlInterval()])->fetchColumn();

            // Predchádzajúce obdobie (pre trend)
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
            // Databáza ešte nie je pripravená — preskoč ticho
        }
    }
}
```

Toto sú dve dôležité veci:

**Modul vlastní svoj prístup k dátam.** Žiadna centrálna služba nezasahuje do `users.db`. `UsersMetric` trieda žije v `Modules\Users` a pýta sa priamo na svoju vlastnú databázu.

**Graceful degradation.** Ak `users.db` ešte neexistuje (prvý boot, migrácia nebola spustená), `catch (\Throwable)` blok znamená, že karta sa nezobrazí. Žiadna chyba, žiadny crash.

## Trend: šípka, ktorá rozpráva príbeh

`Trend` value object počíta porovnanie medzi obdobiami:

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

Metóda `render()` produkuje BEM-styled HTML:

```html
<span class="m-trend m-trend--up">↑ +12.5%</span>
<span class="m-trend m-trend--down">↓ −3.2%</span>
<span class="m-trend m-trend--flat">— 0%</span>
```

Toto je presentation-layer value object. Žije v `Cardboard\Domain\Model`, pretože je to card concern, každý metric provider ho používa, ale len na formátovanie výstupu pre dashboard.

## MetricRegistry: auto-discovery engine

`MetricRegistry` skenuje pre `#[AsMetricProvider]` triedy pomocou rovnakého patternu ako `MiddlewareRegistry`:

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
}
```

Glob pattern `src/Modules/*/Application/Services/*Metric.php` nájde všetky metric triedy naprieč modulmi. Registry cacheuje výsledok, prvé volanie discoveruje, ďalšie vracia cache.

**Kľúčové pravidlo:** metric triedy nesmú mať povinné constructor parametre. Závislosti sa riešia interne cez lazy factories (`DatabaseConnectionManager::instance()->connection('users')`). Toto je rovnaké obmedzenie ako pre middleware.

## DashboardAction: čo sa mení

Starý `DashboardAction` mal šesť private metód pre registráciu kariet. Nový má jednu:

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

Tri riadky logiky. Period prichádza z URL (`?period=30d`). Každý metric provider si registruje vlastné karty. Dashboard nevie, aké metriky existujú.

## Päť modulov

| Modul | Karty | Zdroj dát |
|-------|-------|-----------|
| Users | Users (total + trend), Sessions (active + trend) | `users.db` → users, sessions |
| Blog | Article Views (trend + sparkline), Articles (SK/EN donut) | `blog.db` → article_views, filesystem |
| Comments | Comments (by status donut + trend) | `comments.db` → comments |
| Media | Media (total + uploads trend) | `media.db` → assets |
| Audit | Audit Actions (trend + top actions bar chart) | `audit.db` → audit_log |

Každý modul je nezávislý vertikálny rez. `BlogMetric` žije v `Modules\Blog\Application\Services`. Pýta sa na `blog.db` a počíta articles z filesystemu. Nikdy sa nedotkne `users.db` ani `comments.db`.

## Pridanie nového modulu

Pre hypotetický `Analytics` modul:

1. Vytvor `src/Modules/Analytics/Application/Services/AnalyticsMetric.php`
2. Anotuj s `#[AsMetricProvider(priority: 50)]`
3. Implementuj `MetricContract::registerCards(Period $period)`
4. Hotovo

Žiadne editovanie `DashboardAction`. Žiadne editovanie `public/index.php`. Žiadne editovanie iného súboru. `MetricRegistry` objaví novú triedu pri ďalšom requeste.

Odobranie modulu: vymaž súbor. Registry ho už nenájde.

## C-Pattern kompatibilita

Metric systém dodržiava všetky Nativa Minimal architecture pravidlá:

- **Žiadne magic methods**: `Trend` a `Period` sú obyčajné value objects
- **Žiadna Reflection mimo `Modules\Core`**: `MetricRegistry` žije v `Cardboard\Infrastructure\Registry`
- **Žiadne `eval()`**: všetok kód je explicitný
- **Žiadne service locators**: metric triedy riešia vlastné závislosti cez `DatabaseConnectionManager::instance()`
- **Metódy pod 60 riadkov**: každá `registerCards()` metóda je zameraná a krátka
- **Žiadne spekulatívne interfaces**: `MetricContract` má 5 implementácií
- **Domain izolácia**: `MetricContract` žije v `Cardboard\Domain\Contracts`; modulové metric triedy v `Application/Services` vrstve každého modulu

## Výsledok

Dashboard, kde:

- Každý modul vlastní svoje metriky
- Výber obdobia funguje pre všetky karty (7d / 30d / 90d)
- Trend šípky ukazujú rast alebo pokles na prvý pohľad
- Pridanie metriky modulu vyžaduje jeden súbor, jeden atribút
- Žiadna centrálna koordinácia
- Žiadny dependency injection framework
- Žiadne Composer balíky v produkcii

Celý metric systém je 5 nových súborov (Trend, Period, MetricContract, AsMetricProvider, MetricRegistry) plus 5 modulových metric tried. Každá modulová metric trieda má pod 60 riadkov.

---

→ [MoonShine Cardboard](/blog/moonshine-cardboard): ako MoonShine UI inšpiroval design admin panelu
→ [Middleware Pipeline](/blog/middleware-pipeline): auto-discovery pattern pre middleware
→ [Module Isolation](/blog/module-isolation): prečo moduly nemajú poznať jeden druhého

## Zdroje

- [PHP, Attributes](https://www.php.net/manual/en/language.attributes.php): #[AsMetricProvider] a reflexia
- [PHP Manual](https://www.php.net/manual/): oficiálna PHP dokumentácia
- [PHP-FIG, PSR Standards](https://www.php-fig.org/): PHP štandardy
- [Nativa Minimal Documentation](/docs/getting-started): oficiálny getting started sprievodca
