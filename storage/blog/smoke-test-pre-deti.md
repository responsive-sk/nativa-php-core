---
title: "Clean code pre deti ... diel 5: smoke testy ... detektory, ktoré hliadkujú pravidlá za teba"
date: "2026-08-11"
version: "1.0.2"
tags: ["clean-code", "php", "architecture", "testing"]
excerpt: "Pravidlo bez testu sa nedodržiava. Diel 5 vysvetlí, ako CorePatternTest skenuje celé src/ cez tokeny (nie regex na text), prečo sa nedá oklamať komentárom a ako červený test ukončil diskusiu o 65-riadkovej metóde handle()."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architecture"
translation: "smoke-tests"
published: true
---

> Toto je diel 5 série **Clean code pre deti**. V [dieli 1](/blog/co-je-cisty-kod-pre-deti)
> sme si povedali, že kód sa číta viac, než píše, v [dieli 2](/blog/nazvy-su-dokumentacia-pre-deti),
> prečo sú názvy dokumentácia zadarmo, v [dieli 3](/blog/jedna-vec-srp-pre-deti),
> prečo metóda aj trieda robia len jednu vec, a v [dieli 4](/blog/dry-vs-abstrakcia-pre-deti),
> prečo je predčasná abstrakcia horšia ako duplicita. Všetky tie pravidlá majú
> jedno spoločné: **toto repo ich nevynucuje odporúčaním, ale testom.**
> Dnes si ukážeme, ako tie testy fungujú ... a prečo sa im hovorí smoke testy.

## Smoke signál, ale automatický

V [dieli 3](/blog/jedna-vec-srp-pre-deti) sme si zaviedli pojem **smoke
signál** ... výstražný dymový signál, že sa v kóde niečo deje: „metóda
robí A, B a C“, „tento názov klame“, „tieto dve kópie sa už rozchádzajú“.
Je to užitočný tréning očí ... ale má jednu slabinu: **musí si ho všimnúť
človek.** Pri code review, o polnoci, po treťom šálku kávy. A tu prichádza pointa tohto repa: **prečo nechať človeka hľadať dym,
keď môžeš postaviť detektor, ktorý ho cíti za teba ... vždy, na každom
commite, bez výnimky?**

Tomu hovoríme **smoke test** ... test, ktorý hlási výstražný dymový signál
automaticky. Nepíše sa, aby overil, že funkcia počíta správne (to sú
klasické testy). Píše sa, aby **hliadkoval pravidlo**: „prejdi celý `src/`
a povedz mi, či sa niekde neobjavil dym“.

## Pravidlo bez testu sa nedodržiava

Spomeň si na scenár z [dielu 1](/blog/co-je-cisty-kod-pre-deti) ... code
review bez automatického pravidla:

- Developer: „Tá metóda má 120 riadkov, ale…“
- Autor: „Ale je to logické, všetko súvisí!“
- Developer: „Myslím, že by sa to dalo rozdeliť…“
- Autor: „Tak to nechám na refaktoring, mám deadline.“

A koniec. Každý má argument, nikto nemá pravdu. Ale keď je pravidlo
**test**, diskusia končí skôr, než začne. Nie preto, že by boli ľudia
zlí ... ale preto, že **pamätať si šesť pravidiel pri každom commite
nedokáže nikto.** Detektor áno. Je to presne to isté, čo dymový hlásič:
nebudí ťa preto, že by si spal nedbale, ale preto, že dym cíti aj vtedy,
keď ty ho necítiš.

## Päť detektorov v `CorePatternTest`

Všetky pravidlá série hliadkuje jeden súbor ...
`tests/Architecture/CorePatternTest.php` ... a v ňom žije **päť detektorov**.
Každý má vlastnú metódu, vlastný spôsob hľadania a vlastnú hlášku:

| Detektor | Čo hliadkuje | Ako hľadá |
|---|---|---|
| `testNoOversizedMethods()` | chunky rule ... metódy nad 60 riadkov | tokenový skener: spočíta `{` a `}` |
| `testNoDynamicMagicMethods()` | magické metódy `__call`/`__get`/… | regex na deklarácie `function __x(` |
| `testNoDynamicCodeExecution()` | `eval()` a `create_function()` | regex na volania |
| `testNoReflectionOutsideCoreModule()` | reflexia mimo `Modules\Core` | regex na `Reflection*` |
| `testNoServiceLocatorCalls()` | `Container::get()` / `->make()` / `->resolve()` | **skener kódu bez komentárov** |

A k nim patrí ešte šiesty ... `testNoSpeculativeSingleImplementationInterfaces()`
z [dielu 4](/blog/dry-vs-abstrakcia-pre-deti) ... ktorý tokenovým analyzátorom
počíta rozhrania s jednou implementáciou (baseline 0). Dnes sa pozrieme na to, čo majú spoločné: **prečo čítajú tokeny, nie text.**

## Prečo tokeny, a nie „hľadaj text“

Keď chceš zistiť, či je v kóde `eval()`, najjednoduchšie je povedať
„hľadaj reťazec `eval(`“. Ale to je pasca ... a presne tu sa detektory
tohto repa líšia od naivných. Pozri sa na service-locator detektor:

```php
public function testNoServiceLocatorCalls(): void
{
    // …
    $content = file_get_contents($file);
    // …
    // Scan code only ... docblocks and comments must not trip the tripwire.
    $codeOnly = $this->codeOnly($content);

    if (preg_match('/(?:ServiceContainer|Container)(?:::|->)\s*(?:get|make|resolve|instance)\(/', $codeOnly)
        || preg_match('/->(?:make|resolve)\(/', $codeOnly)
    ) {
        $violations[] = $this->relative($file);
    }
}
```

Všimni si riadok `$codeOnly = $this->codeOnly($content);` ... kľúč.
Metóda `codeOnly()` preženie súbor cez PHP tokenizér a **vyhodí všetky
komentáre a docbloky**:

```php
private function codeOnly(string $content): string
{
    $code = '';

    foreach (token_get_all($content) as $token) {
        if (\is_array($token)) {
            if (T_COMMENT === $token[0] || T_DOC_COMMENT === $token[0]) {
                continue;
            }

            $code .= $token[1];
        } else {
            $code .= $token;
        }
    }

    return $code;
}
```

Prečo je to dôležité? Pretože **PHP tokenizér vie, čo je kód a čo
komentár.** Obyčajný textový grep to nevie. A z toho plynú dva druhy
chybovosti, ktorým sa tokenový skener vyhýba:

**Falošný poplach.** Napíšeš si do docbloku „Pozor: nikdy nevolať
`Container::get()` z tohto modulu“ ... a grep na `Container::get(` spadne,
aj keď si neurobil nič zlé. Docblok ťa potrestá za to, že si chcel byť
slušný. Tokenový skener docblok vyhodí a pokračuje ďalej.

**Oklamanie.** Naopak ... napíšeš si do docbloku výhovorku „toto je jediné
miesto, kde voláme `Container::get()`“ a pod ňu schováš skutočné volanie
medzi riadkami. Textový grep ti to môže prehliadnuť (alebo naopak);
tokenizer to neprehliadne nikdy, lebo volanie je kód.

Pravidlo, ktoré z toho plynie, je presne to, čo sme sa učili v [dieli 4](/blog/dry-vs-abstrakcia-pre-deti):
**pravidlo sa nedá obísť ani omylom, ani úmyselne.** Detektor číta kód,
nie výhovorky. Komentár nie je kód ... a nikdy sa neráta.

## Chunky detektor: počíta zátvorky, nie slová

Najzaujímavejší detektor je chunky rule ... ten, ktorý meria, či sa metóda
zmestí na jednu obrazovku (60 riadkov). Tu by textový grep zlyhal úplne:
nemôžeš „hľadať text“, keď potrebuješ zmerať **veľkosť tela metódy**.

Detektor to robí po tokenoch ... nájde `function`, potom prvý `{` (to je
začiatok tela), a odtiaľ počíta hĺbku: každé `{` ju zvýši, každé `}`
zníži. Keď hĺbka klesne na nulu, skončilo telo. Porovná riadky:

```php
if (null !== $closeLine && $closeLine - $openLine > self::MAX_METHOD_LINES) { $violations[] = sprintf( '%s::%s (%d lines)', $this->relative($file), $name, $closeLine - $openLine );
}
```

A pozor na jeden jemný detail ... metóda, ktorá nemá telo, sa preskočí:

```php
// A ';' before any '{' means a bodyless declaration (interface or
// abstract method) ... return null so it is skipped.
if (';' === $t) { return null;
}
```

Rozhranie má deklaráciu `public function foo(): int;` ... žiadne telo,
žiadne `{`. Tokenový skener to spozná podľa bodkočiarky a nepočíta
„nulové riadky“. Textový grep by sa pri takýchto okrajových prípadoch
topil; tokenizer ich rieši prirodzene, lebo **rozumie štruktúre jazyka.**

## Reálny príbeh: keď detektor chytil 65 riadkov

A teraz najlepšia časť ... toto repo má reálny príbeh, keď detektor
chytil porušenie skôr, než si ho všimol človek. Je zdokumentovaný
v [záverečnom dieli typovej série](/blog/chunky-rule-pre-deti), takže
ho tu len v skratke:

Pri refaktorovaní `BlogArticleAction` pribudla do metódy `handle()`
normalizácia tagov. Test spadol s hláškou:

```
Methods longer than 60 lines are forbidden (chunky: one screen per function):
src/Modules/Core/Interfaces/HTTP/Actions/BlogArticleAction.php::handle (65 lines)
```

**Päť riadkov nad limit.** Nikto to nezistil pri code review. Nikto to
nepokazil úmyselne. Detektor to nameral ... a oprava bola presne
tá, ktorú učí celá séria: `handle()` ostal príbehom a detaily sa
presťahovali do pomenovaných metód (`metaBits()`, `tagChips()`).

Všimni si, čo sa v tom momente stalo: test nebol nepriateľ, bol **hlásič**.
Nehádal sa s tebou, nemusel si argumentovať ... povedal „65 riadkov“ a ty si
vedel presne, čo máš spraviť. Pravidlo, ktoré sa nedá obísť, sa nemusí
obhajovať.

## Automatické pravidlo = koniec diskusie

Toto je filozofia, ktorá spája všetkých päť detektorov: **keď je pravidlo
automatické, nemusíš o ňom diskutovať.** Code review sa môže sústrediť
na to, čo detektor nikdy nezmeria ... na dizajn, na doménu, na príbeh.
O tom, či je metóda príliš dlhá, už nikto nediskutuje: test to povie. A to je aj odpoveď na otázku „nie je 60 riadkov málo?“ ... možno. Ale
hranica nie je dôležitá tým, či je 60 alebo 80. Dôležitá je tým, že
**je.** Jednoznačná, merateľná, automatická. Vágne pravidlo „metódy by
mali byť krátke“ sa nedodržiava; pravidlo „60 riadkov, merané tokenmi,
vynútené testom“ sa dodržiava bez rozmýšľania. Smoke testy sú jedna vrstva kontroly. V [OpenSpec sérii](/blog/spec-ako-zdroj-pravdy-pre-deti)
sme si ukázali, že projekt kontroluje pravidlá na **štyroch
vrstvách** ... spec, kód, testy a QA ... a architektúra testy sú tá vrstva,
ktorá drží zvyšok pohromade: spec môže povedať „žiadne magické metódy“,
ale až `CorePatternTest` zaručí, že sa to naozaj nestane.

## Zhrnutie dielu 5

1. **Smoke signál ≠ smoke test** ... signál si musí všimnúť človek, test ho chytí automaticky, na každom commite.
2. **Pravidlo bez testu sa nedodržiava** ... pamätať si šesť pravidiel nedokáže nikto; dymový hlásič to zvládne.
3. **Päť detektorov v `CorePatternTest`** ... chunky rule, magické metódy, `eval()`, reflexia mimo Core, service locatory (plus baseline 0 z dielu 4).
4. **Tokeny, nie text** ... `codeOnly()` vyhodí komentáre, takže detektor sa nedá oklamať ani omylom, ani úmyselne; chunky detektor počíta `{` a `}`, a bodkočiarkou spozná metódu bez tela.
5. **Reálny príbeh** ... `handle()` spadol na 65 riadkoch (5 nad limit), detektor to nameral skôr než človek, split na helpery to opravil.
6. **Odkaz na ďalší diel** ... detektory strážia pravidlá v rámci modulov; ale kto stráži hranice **medzi** modulmi? O tom, ako `ModuleIsolationTest` hliadkuje, kto s kým môže rozprávať, bude záverečný [diel 6](/blog/modulova-izolacia-pre-deti).

→ [Jedna vec (SRP)](/blog/jedna-vec-srp-pre-deti) — metóda robí jednu vec
→ [Modulová izolácia](/blog/modulova-izolacia-pre-deti) — hranice medzi modulmi
→ [DRY vs abstrakcia](/blog/dry-vs-abstrakcia-pre-deti) — duplicita vs predčasná abstrakcia

## Zdroje

- [PHP Manual](https://www.php.net/manual/) — oficiálna PHP dokumentácia
- [PHP-FIG — PSR Standards](https://www.php-fig.org/) — PHP štandardy
- [Nativa Minimal Documentation](/docs/getting-started) — oficiálny návod
- [OWASP Cheat Sheet Series](https://cheatsheetseries.owasp.org/) — bezpečnostné best practices
- [W3C — Web Standards](https://www.w3.org/standards/) — web štandardy
