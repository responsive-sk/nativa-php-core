---
title: "Clean code pre deti ... diel 1: čo je čistý kód a prečo sa číta viac, než píše"
date: "2026-08-11"
version: "1.0.2"
tags: ["clean-code", "php", "architecture", "refactoring"]
excerpt: "Nový seriál. Čistý kód nie je estetika ... je to udržateľnosť. Diel 1 vysvetlí, prečo sa kód číta desaťkrát častejšie, než píše, ukáže mapu celej série a prvý reálny príklad z tohto repo."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architecture"
translation: "clean-code-part-1"
published: true
---

Videli ste niekedy kód, ktorý ste napísali pred rokom a nespoznali ho? Nie preto, že by bol zložitý. Ale preto, že ste v ňom neboli. A teraz si predstavte, že kód niekto číta prvýkrát. Bez vás. Bez kontextu. Len s tým, čo ste napísali.

## Recept, ktorý sa neprečíta

Predstavte si, že píšete recept. Keď ho píšete, viete presne, čo znamená „štipka soli“ ... veď ste si to práve vymysleli. Ale o pol roka príde niekto iný (alebo ty sám) a bude sa podľa neho snažiť uvariť. „Štipka“ mu už taká jasná nebude.

S kódom je to rovnaké, len ešte prísnejšie. **Kód sa napíše raz, ale číta sa stokrát** ... pri code review, pri hľadaní chyby, pri pridávaní novej funkcie, pri refaktoringu. Každý, kto kód číta, platí za tvoju nedbalosť svojim časom.

A teraz to najdôležitejšie: čitateľ si **nemôže zavolať** a spýtať sa ťa, čo si tým myslel. Jediné, čo má, je to, čo si napísal.

## Keď názov hovorí sám za seba

Skúste si prečítať tento riadok z tohto projektu:

```php
$tagChips = $this->tagChips($meta);
```

Čo robí? **Viete to bez toho, aby ste čítali telo metódy.** Názov hovorí: „vyrob tag chipy z metadát“. Príbeh je jasný už z volania.

A teraz sa pozrite, ako vyzerá celá metóda `handle()` v `BlogArticleAction` ... srdce článkovej stránky `/blog/{slug}`:

```php
public function handle(Request $request): Response
{
    // 1. Načítaj a over slug
    $slug = (string) ($request->routeParam('slug') ?? '');
    if ('' === $slug || 1 !== preg_match('/^[a-z0-9][a-z0-9_-]*$/i', $slug)) {
        return $this->notFound($request->getPathInfo());
    }

    // 2. Načítaj súbor článku
    $file = AppPaths::instance()->storage('blog' . DIRECTORY_SEPARATOR . $slug . '.md');
    if (!is_file($file)) {
        return $this->notFound($request->getPathInfo());
    }

    $source = file_get_contents($file);
    if (!is_string($source)) {
        return $this->notFound($request->getPathInfo());
    }

    // 3. Získaj metadáta (title, excerpt, …)
    $renderer = new MarkdownRenderer();
    $parsed = $renderer->extractFrontMatter($source);
    $meta = $parsed['meta'];

    $title = is_string($meta['title'] ?? null) ? $meta['title'] : ucfirst($slug);
    $excerpt = is_string($meta['excerpt'] ?? null) ? $meta['excerpt'] : '';

    // 4. Poskladaj časti stránky ... každá je pomenovaná
    $resolver = new BlogAuthorResolver(DatabaseConnectionManager::instance());
    $avatar = new AuthorAvatar(new LocalStorageProvider());
    $metaLine = $this->metaLine($meta, $this->authorHtml($meta, $resolver, $avatar));
    $tagChips = $this->tagChips($meta);
    $coverImage = $this->coverImage($slug, $meta);

    // 5. Zabaľ do layoutu
    $content = '<article class="st-article">'
        . '<header class="st-article__header">'
        . $coverImage
        . '<h1 class="st-article__title">' . FrontendLayout::e($title) . '</h1>'
        . '<p class="st-article__meta">' . $metaLine . '</p>'
        . '</header>'
        . $renderer->render($parsed['body'])
        . $tagChips
        . '</article>';

    return Response::html(FrontendLayout::render(
        $title . ' ... Nativa Starter',
        $content,
        description: $excerpt,
        currentPath: $request->getPathInfo(),
    ));
}
```

Všimnite si príbeh. Nemusíte vedieť, **ako** sa robia tag chipy, aby ste pochopili, **čo** sa deje:

1. načítaj a over slug → 2. načítaj súbor → 3. zober metadáta → 4. poskladaj časti → 5. zabaľ do layoutu.

Detaily („ako?“) žijú v pomenovaných helperoch: `metaLine()`, `tagChips()`, `coverImage()`, `authorHtml()`. Každý z nich odpovedá na jednu otázku. A `handle()` len **rozpráva príbeh** ... bez scrollovania, bez hľadania, bez držania kontextu v hlave.

## Prečo test, a nie diskusia?

Tu je miesto, kde sa Nativa líši od väčšiny projektov. Väčšina tímov píše „clean code guidelines“ do dokumentu a dúfa, že ich ľudia dodržia. Projekt napísal **testy, ktoré pravidlá vynucujú** ... keď ich porušíte, build spadne. Nie je to „odporúčanie“, je to **červený test**.

Skúste si predstaviť code review bez chunky testu:

- Developer: „Tá metóda má 120 riadkov, ale…„
- Autor: „Ale je to logické, všetko súvisí!“
- Developer: „Myslím, že by sa to dalo rozdeliť…„
- Autor: „Tak to nechám na refaktoring, mám deadline.“

A koniec. Obaja majú argument, pravdu nemá ani jeden, metóda ostane. Keď je pravidlo **automatické**, o ničom sa nediskutuje: test spadne, metódu rozdelíte, build prejde. Hotovo.

Pozrite, čo všetko stráži `tests/Architecture/CorePatternTest.php`:

| Pravidlo | Čo zakazuje | Ako test hľadá |
|---|---|---|
| Žiadne metódy nad 60 riadkov | „chunky rule“ ... metóda sa musí zmestiť na obrazovku | tokenový skener prejde celé `src/` |
| Žiadne magické metódy | `__call`, `__get`, `__set`, … | regex na deklarácie funkcií |
| Žiadna reflexia mimo Core | `ReflectionClass` a spol. v iných moduloch | regex na `Reflection*` |
| Žiadny `eval()` | dynamické vykonávanie kódu | regex na `eval(` a `create_function(` |
| Žiadne špekulatívne rozhrania | interface s jedinou implementáciou | tokenový analyzátor + baseline 0 |
| Žiadne service-locator volania | `Container::get()` / `->make()` / `->resolve()` mimo Core | skenuje **len kód**, nie docbloky |

Všimnite si ešte jednu vec v tabuľke: „tokenový skener“ a „skenuje len kód, nie docbloky“. Pri kontrole service lokátorov test doslova **vymaže komentáre a docbloky** z kódu a potom hľadá zakázané volania ... takže sa nedá oklamať napísaním výhovorky do docbloku, ale ani omylom nezarazí nevinná zmienka v komentári. Pravidlo platí, bodka.

## Čistý kód nie je estetika

Čistý kód nie je o tom, aby kód vyzeral pekne. Je o tom, aby **prežil vašu neprítomnosť**. Keď odídete z projektu, keď sa pridá nový člen, keď sa o pol roka vrátite k vlastnému kódu.

Čistý kód je rešpekt k človeku, ktorý ešte neprišiel. K starému kolegovi, ktorý číta prvýkrát. K dieťaťu, ktoré sa učí. K zariadeniu, ktoré nemá dosť miesta na chyby.

→ [Názvy sú dokumentácia](/blog/nazvy-su-dokumentacia-pre-deti) — ako spoznať problematické meno skôr, než začne bolieť hlava
→ [Jedna vec (SRP)](/blog/jedna-vec-srp-pre-deti) — metóda aj trieda robia len jednu vec
→ [DRY vs abstrakcia](/blog/dry-vs-abstrakcia-pre-deti) — jedno miesto pravdy, žiadne rozhrania do zásoby

## Zdroje

- [PHP Manual](https://www.php.net/manual/) — oficiálna PHP dokumentácia
- [PHP-FIG — PSR Standards](https://www.php-fig.org/) — PHP štandardy
- [OWASP Cheat Sheet Series](https://cheatsheetseries.owasp.org/) — bezpečnostné best practices
- [Nativa Minimal Documentation](/docs/getting-started) — oficiálny návod
- [W3C — Web Standards](https://www.w3.org/standards/) — web štandardy
