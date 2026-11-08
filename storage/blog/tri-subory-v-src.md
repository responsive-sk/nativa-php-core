---
title: "Tri súbory v src : ako tečie request od front controlleru po Response"
date: "2026-10-18"
tags: ["nativa", "php", "architektúra", "middleware", "routing", "cache"]
excerpt: "Front controller, tri vrstvy bootstrapu a celý tok requestu od public/index.php po Response : vrátane page cache, CLI vetvy a dôvodu, prečo src/ obsahuje tri súbory."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architektúra"
published: true
---

## Časť I > Poznámka, ktorá vyzerá ako sťažnosť na poriadok

> „teraz mame 3 subory v src > vcera bol len init ...“

To je presne ten typ poznámky
ktorá sa dá vyriešiť presúvaním súborov
a pritom nechať skutočnú otázku nezodpovedanú.
Otázka nie je „koľko súborov má `src/`“.
Otázka je **ktorý proces ktorý súbor naozaj potrebuje**
a podľa toho, či sa odpoveď zhoduje s tým, ako sú súbory rozdelené.

Dnešný stav v koreni `src/`:

```
src/
├── init.php          # bootstrap, ktorý zdieľa HTTP aj CLI aj testy
├── composition.php   # párovanie modulov, ktoré musí mať každý delivery proces
└── http.php          # HTTP composition root — celá webová aplikácia
```

Pred dnešnou zmenou boli v `src/` dva súbory
`init.php` a `composition.php`.
`composition.php` nevznikol dnes
do stromu ho prinieslo CLI kolo 16. októbra (queue worker
a `outbox:process`), keď sa ukázalo
že párovanie Audit↔Outbox nesmie žiť len v tom procese
ktorý obsluhuje HTTP.
Dnešná zmena pridala tretí súbor:
`http.php`.
Ten presunul wiring z `public/index.php`, ktorý mal dovtedy 210 riadkov.

Takže „včera bol len init“ je pravda o stave **spred** CLI kola, nie o včerajšku
17. októbra už boli v `src/` dva súbory.
A odpoveď na „prečo tri“ nie je „lebo sme pridali súbor“
je to **rozsah platnosti**
tri rôzne množiny procesov potrebujú tri rôzne veci.
Nižšie je celý tok, aby bolo vidno
kde presne sa ktorý súbor načíta a prečo nemôže byť v tom druhom.

---

## Časť II > HTTP request, krok za krokom

### 1. Web server → `public/index.php`

Server (nginx, Apache, `php -S`) pošle request do `public/index.php`.
Ten má dnes šestnásť riadkov a okrem komentára obsahuje dve veci:

```php
require_once __DIR__ . '/../src/http.php';

run_http_application();
```

To je celý front controller.
Žiadne `use`, žiadne middleware, žiadna pipeline.
Je to jediné miesto, ktoré SAPI pozná; keby sa sem vrátil wiring
každý ďalší vstupný bod (test harness, CLI
budúci worker) by mal druhú kópiu.

### 2. `src/http.php` > early logging a bootstrap

`http.php` sa začne načítať a **prvé, čo urobí, je early error logging**:

```php
error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);
$_earlyLogDir = dirname(__DIR__) . '/storage/logs';
// ... mkdir + ini_set('error_log', ...)
```

Toto musí bežať **pred** `init.php`
inak by pád počas samotného bootstrapu nemal kam zapísať.
Preto je early blok v `http.php`, nie v `init.php`
CLI (`bin/cms`) tento blok nepotrebuje a nemá dôvod meniť svoje chovanie.

Potom `http.php` načíta dve zdieľané vrstvy:

```php
require_once __DIR__ . '/init.php';
require_once __DIR__ . '/composition.php';
```

### 3. `src/init.php` > čo znamená „init“

`init.php` je **generický bootstrap**, nie HTTP bootstrap.
V poradí robí:

| # | Čo | Prečo |
|---|---|---|
| 1 | Composer autoloader, ak existuje `vendor/` | vývoj |
| 2 | PSR-4 fallback pre `Modules\`, `Templates\`, `Themes\` | runtime bez `vendor/` |
| 3 | `define('APP_BASE_PATH', dirname(__DIR__))` | jedna absolútna cesta |
| 4 | `Env::loadImmutable('')` | `.env` sa načíta raz, nemenné |
| 5 | `header_remove('X-Powered-By')` | fingerprint PHP verzie |
| 6 | error reporting + `set_error_handler` + `set_exception_handler` | warning = `ErrorException`; fatal = log + 500 stránka |
| 7 | `config/session.php` | session sa **nespúšťa**, len nakonfiguruje |
| 8 | `date_default_timezone_set()` | `APP_TIMEZONE` |

Session je zámerne len pripravená.
Middleware (`CsrfMiddleware`
`AuthMiddleware`) a akcie si ju otvoria až vtedy, keď ju naozaj potrebujú
anonymná home page nemá dôvod zapisovať session súbor.

`init.php` **nespúšťa HTTP aplikáciu** a ani nemôže
ten istý súbor načítava `bin/cms` a `tests/bootstrap.php`.
Keby na konci `init.php` volal `run_http_application()`
každý CLI príkaz a každý test by sa pokúsil odpovedať na fiktívny request.

### 4. `src/composition.php` > párovanie, ktoré discovery nevie

`composition.php` pri načítaní **nič nevykoná**.
Definuje iba dve funkcie:

```php
function wire_module_handlers(): void   // Audit ↔ Outbox + Queue ↔ Blog cover
function wire_queue_handlers(): void
```

Prečo nemôže byť v `http.php`?
Lebo `bin/cms` ho potrebuje tiež.
Keď bolo toto párovanie inline v HTTP entry pointe
existovalo len v procese, ktorý obsluhoval web.
Worker v CLI našiel prázdnu mapu handlerov
a každú správu odložil ako `No handler registered for event type`
zatiaľ čo HTTP odpovede vyzerali zdravo.
To je Boot 16.
`src/http.php` preto `wire_module_handlers()` **volá**, nevlastní ho.

### 5. `run_http_application()` → `http_kernel()`

`http.php` má dve funkcie.
`run_http_application()` je krátka:

```php
$response = http_kernel()->handle(Request::createFromGlobals());
$response->send();
DisposalRegistry::disposeAll();
```

`http_kernel()` postaví svet v presnom poradí:

#### 5a. Migrácie > `Bootstrapper::boot()`

`Bootstrapper` najprv overí
že `storage/{data,logs,cache,sessions,uploads}` existujú
a sú **zapisovateľné**.
To nie je dekorácia:
neúspešný zápis do `cache/` znamená
že sa marker migrácií nikdy nezapíše
a celá schéma sa bude spúšťať pri každom requeste
z 500 sa stane timeout.

Potom sa pozrie na `storage/cache/.migrations-applied`.
Ak marker chýba, spustí všetky `#[AsMigrator]` migrátory (idempotentne).
Marker zapíše len vtedy, keď aspoň jeden migrátor naozaj bežal
prázdny strom tak zostane
„ozbrojený“ pre prvý modul, ktorý migrátor prinesie.
S nainštalovanými modulmi to vytvorí ich tabuľky
v kernel-only strome len tabuľky jadra.

#### 5b. Párovanie handlerov > `wire_module_handlers()`

Až teraz, po migráciách, sa zaregistrujú handlery.
Poradie je dôležité:
handler môže potrebovať tabuľku
ktorú migrácia práve vytvorila.

#### 5c. Discovery > `DiscoveryCache`

```php
$discovery = DiscoveryCache::fromAppPaths();
$payload = $discovery->build(
    fn () => (new Router($actionDirs))->toEntries(),
    fn () => MiddlewareRegistry::discoveredClasses(),
);
$router = Router::fromEntries($actionDirs, $payload['routes']);
MiddlewareRegistry::prime($payload['middleware']);
```

`DiscoveryCache` je fingerprint-validated
fingerprint sleduje všetky `.php` súbory v scan adresároch (path + veľkosť
+ mtime
súbor zmenený v rovnakej sekunde ako build prispieva content hashom).
Ak sedí, router a zoznam middleware sa načítajú bez skenovania.
Akákoľvek chyba cache (corrupt súbor
zmenený fingerprint) padá do `catch` a urobí **priamy scan**.
Corrupt cache teda stojí jeden scan, nie 500.

#### 5d. `Kernel` > objavená časť pipeline

```php
$kernel = new Kernel($router);
```

Konštruktor si cez `MiddlewareRegistry::all()` vytiahne všetky triedy
s `#[AsMiddleware]`, zoradí ich podľa priority a vytvorí inštancie.
Musia mať **nulový konštruktor**
to je celý dôvod, prečo sú niektoré veci explicitné.

V tomto strome to sú:

| Priorita | Middleware | Kde žije |
|---|---|---|
| 0 | `SecurityHeadersMiddleware` | Core |
| 10 | `LocaleMiddleware` | Locales, ak je modul nainštalovaný |
| 20 | `CsrfMiddleware` | Core |

`SecurityHeadersMiddleware` je teda najvrchnejší
dopĺňa CSP nonce aj do odpovede, ktorá prišla z page cache.

#### 5e. Explicitný wiring > tri stavy, ktoré discovery nevie skonštruovať

```php
// 1. Rate limiting — potrebuje limiter factory a PDO
if (class_exists(SecurityRateLimiter::class)) { ... }

// 2. Page cache — potrebuje PageCacheService
if (Env::bool('PAGE_CACHE', false)) { ... }

// 3. Auth — provider je objavený cez #[AsAuthProvider]
$authProvider = AuthProviderRegistry::first();
if (null !== $authProvider) { ... }
```

Každý blok zaloguje **práve jeden riadok** (`[rate] …`, `[cache] …`
`[auth] …`)
v produkcii
sa z logu dá rozlíšiť „modul nie je nainštalovaný“ od „modul je rozbitý“
bez čítania kódu.

`class_exists` guard pri rate limite je zámerný a má aj druhú polovicu
návratový typ factory je `object`, nie `SecurityRateLimiter`.
Typ v anotácii sa totiž vyhodnocuje **aj keď je `new` za guardom**
a v kernel-only checkoute bez `Modules\Security` by to bol fatal.

### 6. `Kernel::handle()` > cibuľa middleware

`Kernel` zoberie zoznam middleware a zabalí ich od konca:

```
SecurityHeaders → Locale → Csrf → RateLimit → PageCache → Auth → dispatch
```

Poradie nie je náhodné:

- **RateLimit je pred PageCache** : flood sa počíta skôr, než sa vráti cache
  hit. Inak by cache obchádzala limiter.
- **PageCache je pred Auth** : ale signed-in visitor sa do cache vôbec
  nedostane, lebo `PageCacheService::isCacheableRequest()` kontroluje session
  cookie. Bez toho by editor dostal stránku s admin odkazmi pre anonymného
  čitateľa.
- **Auth je posledná pred dispatchom** : chráni admin routes skôr, než sa
  vôbec nájde akcia.

Každý middleware môže request buď obslúžiť sám (cache hit, 429
redirect na login), alebo zavolať `$next($request)`.

### 7. Dispatch > od cesty k akcii

Keď request prejde celou cibuľou, `Kernel::dispatch()`:

1. **kanonizuje admin prefix** : `AdminRoutePolicy::canonicalPath()` preloží
   `/admin` na interné `/mark`, takže router vidí vždy jednu spelling;
2. **matchne route** : najprv statické cesty, potom parametrizované
   (`/blog/{slug}`); `/blog/` dostane 301 na `/blog`; zlá metóda dostane 405 +
   `Allow`;
3. **nastaví route params** na request;
4. **vytvorí akciu** cez reflexiu : a ak konštruktor žiada `RouterInterface`,
   vstrekne router (akcie tak vedia tvoriť URL podľa mena route);
5. **zavolá handler** (`handle()` alebo `handlerMethod` z atribútu);
6. **normalizuje výsledok** : `Response`, pole → JSON, skalár → HTML.

### 8. `HomepageAction` > ako vznikne Boson home page

Pre `GET /` sa matchne `HomepageAction`:

```php
$theme     = (new ThemeResolver())->resolve($request);
$locale    = ContentServices::mapper()->defaultLocale();
$siteName  = ShellContextProviderRegistry::siteName() ?? 'Nativa PHP Core';
$copy      = ['title' => $siteName] + ShellContextProviderRegistry::copy('home', $locale);

$content = ThemeKernel::for($theme)->homepage(
    PageContext::forHomepage($request, $theme, $locale, copy: $copy),
);

return Response::html(FrontendLayout::render(
    $siteName, $content, currentPath: '/', canonicalUrl: $homeUrl, theme: $theme,
));
```

Dôležité je, čo tam **nie je**:
žiadny `use Modules\Blog`
žiadny `Modules\Settings`.
Texty, menu a názov webu idú cez `ShellContextProvider` port
kto ich vlastní, odpovie.
Téma (Boson) vykreslí `homepage()`
layout pridá `header()` a `footer()` a vloží kritické CSS.

`FrontendLayout::render()` je spoločný pre všetky témy
vygeneruje `<head>`, canonical, structured data, nonce placeholder
`header`, obsah, `footer`, `pageWrap()` (kritické CSS inline
JS buď defer z `public/dist/boson/`, alebo inline fallback bez buildu).

### 9. `Response::send()` a disposal

`Response::send()` pošle status, hlavičky a telo.
Potom `DisposalRegistry::disposeAll()` spustí cleanup callbacks
všetky, aj keď jeden zlyhá.

---

## Časť III > Kde presne zasahuje page cache

`PageCacheMiddleware` sedí medzi rate limitom a auth.
Jeho pravidlo:

```
cacheovateľný request = GET
                      ∧ nie admin
                      ∧ nie /docs, /debug, /storage
                      ∧ nie login/register/reset
                      ∧ žiadny session-scoped query (form-sent, …)
                      ∧ návštevník nie je prihlásený
```

Keď je odpoveď `200 text/html`, neprázdna a neobsahuje CSRF token
uloží sa do `storage/cache/pages/<sha256>.json`.
Kľúč je:

```
sha256("GET|/||sk_SK")
```

TTL je 300 sekúnd.
Pri cache hite sa vráti telo a hlavičky a `SecurityHeadersMiddleware`
(ktorý je **vonku**) nahradí `__CSP_NONCE_PLACEHOLDER__` čerstvým nonce.
Cache sa nevypisuje ručne:
každý zápis v admine volá `CacheInvalidator`
takže po editácii sa nezobrazí stará stránka.

Zapnúť sa dá bez rebuildu:

```
PAGE_CACHE=true
```

To je jediný prepínač.
V tomto checkoute nie je `.env`, takže platí default `false`
cache je opt-in.
S explicitným `PAGE_CACHE=true` to live smoke potvrdil
prvý request po štarte bol MISS (uložil), druhý už `X-Cache: HIT`.

---

## Časť IV > Ako tečie CLI

`bin/cms` je druhý vstupný bod a **nemá nič z HTTP**:

```
bin/cms
  → require src/init.php          (autoload, env, error handlers, timezone)
  → require src/composition.php   (definície párovaní)
  → main($argv)                   (switch podľa príkazu)
```

Príkazy, ktoré **doručujú** prácu (`schedule:run`, `queue:work`
`outbox:process`), zavolajú `wire_module_handlers()`.
Príkazy
ktoré len zapisujú súbory (`generate-images`) alebo len čítajú stav
(`discovery:status`),
nie registrovať handler mapu, ktorú nikdy neprečítajú, by bolo klamstvo o tom
čo príkaz robí.

`bin/cms` nikdy nevytvorí `Kernel`
nikdy nenačíta `Request` a nikdy nepošle `Response`.
Preto nemôže zdieľať `http.php`.

---

## Časť V > Prečo teda tri súbory

| Súbor | Kto ho načítava | Čo v ňom musí byť |
|---|---|---|
| `src/init.php` | HTTP, CLI, testy | prostredie, autoload, error handlery, session config, timezone |
| `src/composition.php` | HTTP aj CLI delivery príkazy | párovanie modulov, ktoré musí existovať v každom procese |
| `src/http.php` | iba HTTP | early logging, kernel, pipeline, dispatch, disposal |

Keby sa `http.php` zlúčil do `index.php`
front controller by mal 200+ riadkov a každý nový vstupný bod
by ho kopíroval.
Keby sa zlúčil do `composition.php`
HTTP by sa načítavalo v CLI a párovanie by sa miešalo s pipeline.
Keby sa `composition.php` zlúčil do `http.php`
CLI by muselo načítať HTTP vrstvu alebo by párovanie chýbalo v workeri
Boot 16.

**Poznámka k tvaru:
** ak ide o estetiku koreňa `src/`
súbor sa dá presunúť bez zmeny toku:

```
src/
├── init.php
├── Bootstrap/
│   ├── composition.php
│   └── http.php
└── Modules/ ...
```

`src/` by v koreni mal opäť len `init.php`.
Je to čisto presun ciest v `require`, `phpstan.neon`
`.php-cs-fixer.dist.php` a v testoch
žiadna trieda ani správanie sa nemení.
Rozhodnutie, či to urobiť, je o tom, čo má byť v koreni viditeľné
nie o tom, koľko súborov aplikácia potrebuje.

---

## Časť VI > Overenie, že tok je naozaj ten istý

Po presune wiringu z `index.php` do `http.php`:

```
make lint                            OK
composer phpstan                     No errors
composer cs-fix-dry                  0/116
composer test                        555 testov, 5111 asercií, 1 skip
bin/build/extract-kernel.sh --check  OK
```

Live `php -S 127.0.0.1:8099 -t public`:

```
GET /      200, X-Cache: HIT, <main class="bo-main">, "Keep PHP. Drop the framework."
GET /blog  200
```

To je celý dôkaz, ktorý tento článok potrebuje:
front controller je kratší
tok je rovnaký a každá vrstva je na mieste
kde ju daný proces naozaj potrebuje.
