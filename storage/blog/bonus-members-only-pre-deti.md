---
title: "Bonus pre členov ... ako fungujú members-only články v tomto blogu"
date: "2026-08-13"
version: "1.0.1"
tags: ["members-only", "php", "auth", "architecture", "security"]
excerpt: "Bonusový článok len pre registrovaných. Ukážeme si, ako tento blog zamká časť obsahu ... jediné slovo vo front matter, teaser s výzvou na prihlásenie a prečo sa gate nedá obísť ani cez feed, ani cez vyhľadávanie."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Bonus"
members_only: true
translation: "bonus-members-only"
published: true
---

Niektoré články sú zamknuté. Nie preto, aby niekoho vylúčili, ale preto, že niektoré skúsenosti stoja za to, aby sa o ne podelili len s tými, ktorí chcú vidieť viac.

> Tento článok je **bonus pre členov**. Kým si neprihlásený, vidíš len
> tento teaser. Po prihlásení sa odomkne plný obsah ... a prihlásenie trvá
> desať sekúnd. Prečo to blog robí, ako to celé funguje pod kapotou
> a prečo sa zámok nedá obísť? Presne o tom je tento článok. Všetko
> na reálnom kóde z tohto repa.

## Prečo vôbec members-only obsah

Blog je know-how blog ... každý článok vysvetľuje, ako je postavené toto
repo, na reálnych príkladoch z kódu. Až doteraz bol všetok obsah
verejný. Prečo teda niečo zamykať?

1. **Odmena pre registrovaných** ... bonusové články sú vďaka za to,
   že si sa prihlásil. Nie je to paywall, je to odznak.
2. **Vyššia kvalita konverzácie** ... na members-only článkoch môžu
   diskutovať len ľudia, ktorých naozaj poznáš (máš ich email).
3. **Nič nestojí** ... žiadny platobný systém, žiadny nový server.
   Registrácia už existuje, takže zámok je len jeden flag v súbore.

To posledné je dôležité: **members-only nie je nová funkcionalita.
Je to jedna booleovská hodnota vo front matter.** Poďme sa pozrieť, ako.

## Jedno slovo vo front matter

Každý článok v `storage/blog/` je obyčajný Markdown súbor s YAML front
matter. Pridať článok medzi bonusové znamená pridať jeden riadok:

```yaml
---
title: "Bonus pre členov ... ako fungujú members-only články v tomto blogu"
date: 2026-08-13
tags: [members-only, php, auth]
excerpt: "Bonusový článok len pre registrovaných..."
members_only: true
---
```

Parser front matter v `MarkdownRenderer` rozozná `true` a `false`
a uloží ich ako booleany (nie ako stringy `"true"`). Karta článku
potom nesie `members_only: bool` a podľa neho vykreslí na karte
v `/blog` malý odznak **MEMBERS ONLY**.

```php
$badge = $article['members_only']
    ? '<span class="st-blog-index__badge" title="Members only ... sign in to read">Members only</span>'
    : '';
```

Povšimni si: karta je **verejná**. Titulok, excerpt, autor, odznak ...
to všetko vidí každý. Zamyká sa až samotný článok. To je zámer:
bonus musí byť viditeľný, inak by sa k nemu nikto neprihlásil.

## Ako vyzerá zámok pre hosťa

Keď neprihlásený návštevník otvorí bonusový článok, namiesto tela
dostane **teaser stránku** ... `BlogArticleAction` to rozhodne v troch
riadkoch:

```php
if ($meta['members_only'] ?? false && !$this->isMember()) {
    return $this->membersOnlyPage($article, $meta);
}
```

Teaser stránka obsahuje:

- **Titulok a excerpt** ... teaser je presne ten excerpt z front matter.
  Nie je to oreznutý článok, je to samostatne napísané pozvanie.
- **Panel s výzvou** ... nadpis „Member-only article“, text a dve tlačidlá:
  *Sign in* a *Create account*.
- **Žiadny únik** ... žiadny TOC, žiadny formulár komentárov, žiadne
  telo článku. Ani náhodou.

Kľúčové slovo: **telo článku sa vôbec nerenderuje**. Server nemá čo
„zabudnúť zamknúť“ ... pre hosťa tie riadky ani neexistujú. Neposiela
sa CSS trieda, ktorá by obsah skryla (to by sa dalo obísť), alebo
HTML, ktoré by JS odomkol. Pre hosťa je bonusový článok krátka
stránka s pozvaním. Bodka.

## Odkiaľ vie Core, že si prihlásený

Tu prichádza architektonická hádanka. Blog žije v `Modules\Core`,
ktoré je zdieľané jadro ... a auth žije v `Modules\Users`. Pravidlo
modulovej izolácie hovorí: Core nesmie importovať Infrastructure
a Interfaces cudzieho modulu, ale **Application vrstvu môže**.

A presne to Core už robí: `AuthMiddleware` (v Core!) si pýta
`AuthService` z Users cez lazy factory. Článková akcia používa
rovnaký vzor:

```php
private function isMember(): bool
{
    $session = $_SESSION['auth']['user_id'] ?? null;

    if (null === $session) {
        return false;
    }

    $auth = new AuthService(
        new PDO('sqlite:' . AppPaths::instance()->data('users.db')),
        new UserRepository(...),
        new PasswordHasher(),
        new RateLimiter(...),
    );

    return null !== $auth->currentUser();
}
```

Dôležitý detail: **`AuthService` sa vytvorí len keď treba.** Kým
návštevník číta bežný článok, zámok sa ani nespustí ... žiadna
databáza, žiadna session logika navyše. Lazy až za gate.

## Prečo sa zámok nedá obísť

Keď sa zamyká obsah, prvou otázkou je: kde všade by mohol uniknúť?
Prešli sme si všetky cesty, ktorými sa článok šíri:

| Cesta | Čo pošle | Je to únik? |
|---|---|---|
| `/blog/{slug}` (hosť) | teaser + výzva | nie |
| `/blog` (karty) | titulok + excerpt + odznak | nie |
| RSS/Atom feed | titulok + excerpt | nie |
| Vyhľadávanie `?q=` | karta s excerptom | nie |

Najzákernejší by bol **feed**. Keby feed posielal plné telo,
odoberatelia by bonusový článok dostali bez prihlásenia ... zámok
by bol divadlo. Preto feed posiela **len titulok a excerpt** pre
všetky články (to je aj tak jeho zmluva: feed je digest, nie
knižnica). A vyhľadávanie vracia karty ... teda opäť len titulok
a excerpt.

Zostáva jedna cesta, ktorú nemožno zamknúť: **kto má prístup
k súboru na serveri**. Ale to platí pre všetko, aj pre databázu.
Zámok chráni čitateľov webu, nie vlastníka servera.

## Čo sa stane, keď sa prihlásiš

Tlačidlo *Sign in* nevedie na obyčajný `/login`, ale na
`/login?return_to=/blog/bonus-members-only-pre-deti`. Po prihlásení
ťa server **vráti presne na článok**, ktorý si chcel čítať ... a ten
sa odomkne. Žiadne hľadanie v histórii, žiadne „späť“.

A to `return_to` nie je bezpečnostná diera. Hodnota prechádza
validáciou, ktorá povoľuje len lokálne cesty začínajúce `/`:

```php
private static function returnTo(?string $value): string
{
    if (null === $value || 1 !== preg_match('@^/[a-z0-9/._?&=#%+~-]*$@i', $value)
        || str_starts_with($value, '//')
    ) {
        return '/';
    }

    return $value;
}
```

Regulárny výraz nepustí `https://`, dvojitá lomka `//` je zakázaná
osobitne (keby sa `http:` niekto pokúsil vygumovať) ... takže
`return_to` nikdy nepošle používateľa na cudziu doménu. Klasická
open-redirect diera sa tu nevyskytne.

Rovnaký mechanizmus funguje aj po registrácii ... `/register?return_to=...`.
A keď je `return_to` prázdne alebo podozrivé, spadne to na domovskú
stránku. Bezpečné predvolené správanie.

## Pravidlo bez testu sa nedodržiava

Ako všade v tomto repo: čo sa dá otestovať, sa netestuje dojmom.
`BlogMembersTest` má osem testov, ktoré strážia zámok:

- hosť vidí teaser a výzvu, ale **nie** telo článku,
- hosť nevidí TOC ani komentáre na gated článku,
- prihlásený čitateľ vidí plný obsah a žiadny gate,
- `return_to` sa po prihlásení rešpektuje,
- `return_to` s cudzou doménou sa **odmietne**,
- feed posiela titulok a excerpt, nie telo.

Ten posledný je najcennejší: feed je najľahšia cesta, ako zámok
omylom obísť. Kým test hovorí „feed nesmie obsahovať telo bonusového
článku“, môžeš spať pokojne.

## Čo si z toho odniesť

1. **Members-only je jeden flag** ... `members_only: true` vo front
   matter. Nič viac netreba na to, aby článok „bol bonusový“.
2. **Teaser je excerpt** ... zámok je samostatne napísané pozvanie,
   nie oreznuté telo.
3. **Telo sa pre hosťa nerenderuje** ... žiadne CSS/JS skrývačky,
   ktoré by sa dali obísť. Pre hosťa obsah neexistuje.
4. **Feed je najslabší článok** ... feed posiela len titulok a excerpt
   a test to stráži.
5. **`return_to` sa validuje** ... po prihlásení ťa vráti na článok,
   ale nikdy na cudziu doménu.

A ak si sa sem dostal bez prihlásenia ... teraz už vieš, čo ťa čaká.
Prihlás sa a dočítaj si zvyšok. 

→ [Performance diel 5](/blog/performance-pre-deti-diel-5-members-only-biznis): members-only biznis logika
→ [Blog article action](/blog/blog-article-action): ako sa článok renderuje
→ [Comments](/blog/comments): komentáre a rate limiting

## Zdroje

- [W3C, ACID Properties](https://www.w3.org/TR/xml-core/#sec-conformance): ACID definícia
- [PHP Manual](https://www.php.net/manual/): oficiálna PHP dokumentácia
- [SQLite Documentation](https://www.sqlite.org/docs.html): SQLite referenčná príručka
- [OWASP Cheat Sheet Series](https://cheatsheetseries.owasp.org/): bezpečnostné best practices
- [Nativa Minimal Documentation](/docs/getting-started): oficiálny návod
