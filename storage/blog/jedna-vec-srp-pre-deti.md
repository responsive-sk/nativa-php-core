---
title: "Clean code pre deti ... diel 3: jedna vec (SRP) ... metóda aj trieda robia len jednu vec"
date: "2026-08-11"
version: "1.0.2"
tags: ["clean-code", "php", "architecture", "refactoring"]
excerpt: "Jedna metóda, jedna trieda ... jedna vec. Diel 3 vysvetlí, prečo je „toto robí A, B a C“ smoke signál, ako sa handle() rozdelil na helpery a prečo BlogArticleAction, BlogAuthorResolver a AuthorAvatar sú tri triedy s tromi prácami."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architecture"
translation: "one-thing-srp"
published: true
---

> Toto je diel 3 série **Clean code pre deti**. V [dieli 1](/blog/co-je-cisty-kod-pre-deti)
> sme si povedali, prečo sa kód číta viac, než píše, a v [dieli 2](/blog/nazvy-su-dokumentacia-pre-deti),
> prečo sú názvy dokumentácia zadarmo. Dnes sa pozrieme na princíp, ktorý znie
> najjednoduchšie a porušuje sa najčastejšie: **jedna metóda, jedna trieda ...
> jedna vec.** Ak si čítal [typovú sériu](/blog/chunky-rule-pre-deti), spoznáš
> v tom pokračovanie: tam sme si povedali, prečo 60 riadkov, dnes si povieme,
> čo tých 60 riadkov meria ... a že to platí aj pre celé triedy.

## „Toto robí A, B a C“ ... smoke signál číslo jedna

Najrýchlejší test, či je kód v poriadku, znie takto: **dokážeš o metóde alebo
triede povedať jednou vetou, čo robí?** Ak tá veta obsahuje „a“ alebo „potom“
... máš problém. Tomuto hovoríme **smoke signál** ... výstražný dymový signál, že sa v kóde
niečo deje. (Pôvodný anglický termín je Smoke Test; „zápach“ by v slovenčine
zavádzal ... pointa nie je, že kód smrdí, ale že ťa včas varuje.)

- „Tá metóda validuje slug, číta súbor, parsuje YAML a renderuje HTML…“
- „Tá trieda spravuje používateľov, session aj mail…“ Každé „a“ znamená **jednu vec navyše**. A jedna metóda, ktorá robí tri veci,
má tri dôvody, prečo sa môže pokaziť ... a tri dôvody, prečo ju musí niekto
čítať. Kód sa píše raz, číta desaťkrát (diel 1), takže každú vec navyše
platíš deväťkrát znovu pri čítaní.

## SRP na metóde: `handle()` rozpráva, helpery robia

Single Responsibility Principle („jedna zodpovednosť“) platí na dvoch
úrovniach. Tá prvá je metóda. Pravidlo projektu to vynucuje chunky rule ...
metóda sa musí zmestiť na jednu obrazovku (o tom bol [záverečný diel typovej
série](/blog/chunky-rule-pre-deti), nebudem to opakovať). Dôležité je, **čo
tá obrazovka meria**: keď sa metóda nezmestí, zvyčajne to znamená, že nerobí
jednu vec, ale niekoľko. Pozri sa na `handle()` v `BlogArticleAction` ... metódu, ktorá obsluhuje
stránku článku `/blog/{slug}`. Celá je v [dieli 1](/blog/co-je-cisty-kod-pre-deti),
tu si všimni len jej srdce:

```php
public function handle(Request $request): Response
{ // … slug validácia, čítanie súboru, front matter … $resolver = new BlogAuthorResolver(DatabaseConnectionManager::instance()); $avatar = new AuthorAvatar(new LocalStorageProvider()); $metaLine = $this->metaLine($meta, $this->authorHtml($meta, $resolver, $avatar)); $tagChips = $this->tagChips($meta); $coverImage = $this->coverImage($slug, $meta); // … poskladanie stránky z častí a render …
}
```

`handle()` **nerobí** meta linku, tagy ani cover image. On ich len
**požiada**. Každá vec má svoju metódu, ktorá odpovedá na jednu otázku:

| Volanie | Otázka, na ktorú odpovedá | Kde žije odpoveď |
|---|---|---|
| `metaLine()` | Aký je dátum, kategória a autor (už vyrenderovaný)? | filter prázdnych hodnôt + spojenie |
| `tagChips()` | Aké tagy a ako ich vyrenderovať? | normalizácia + HTML chipy |
| `coverImage()` | Aký cover obrázok a s akým atribútom? | rozhodnutie v `resolveCover()` + render `<img>` |
| `authorHtml()` | Ako vyzerá autor (odkaz + avatar)? | len spája: `BlogAuthorResolver` (dáta) + `AuthorAvatar` (markup) | Príbeh je „čo sa stane“, detaily sú „ako“ ... a „ako“ je vždy za rohom,
v metóde, ktorej meno hovorí, čo nájdeš. To je presne to, čo sme sa učili
v dieli 2: meno ako dokumentácia.

## SRP na triede: tri triedy, tri práce

Druhá úroveň je trieda. Tu platí rovnaké pravidlo, len s väčším meradlom:
**každá trieda má jeden dôvod, prečo sa mení.** Ak trieda zmeníš kvôli
dizajnu avatara **aj** kvôli SQL dotazu na používateľa, má dva dôvody ... a to
sú dve triedy. V projekte to nie je teória, ale trojica tried, ktoré spolupracujú na
článkovej stránke:

| Trieda | Robí len | Mení sa, keď |
|---|---|---|
| `BlogArticleAction` | poskladá stránku z častí | štruktúra stránky / flow |
| `BlogAuthorResolver` | vyrieši email → profil autora (SQL) | schéma `users` / dotaz |
| `AuthorAvatar` | vyrobí avatar markup (HTML) | dizajn avatara / placeholder | `BlogAuthorResolver` nevie nič o HTML ... jeho jediná práca je dotaz do
databázy:

```php
final readonly class BlogAuthorResolver
{ /** * Resolve an email to the linked active user's public profile. * * @return array{id: string, name: string, avatar: ?string}|null */ public function profile(string $email): ?array { $row = $this->lookup( 'SELECT id, name, avatar FROM users WHERE email = :email COLLATE NOCASE AND is_active = 1 LIMIT 1', [':email' => $email], ); // … null = fallback na čistý text autora … }
}
```

`AuthorAvatar` zas nevie nič o databáze ... jeho práca je čisto markup:

```php
final readonly class AuthorAvatar
{ /** * Avatar <img> ... the stored file when it exists, else the placeholder. */ public function img(?string $avatar, string $name, int $size = 20): string { $src = null !== $avatar && $this->storage->exists($avatar) ? $this->storage->url($avatar) : $this->placeholderDataUri($name); return '<img class="st-author-avatar" src="' . FrontendLayout::e($src) . '" alt=""' . ' width="' . $size . '" height="' . $size . '" loading="lazy">'; }
}
```

Prečo je to dobré rozdelenie?

**Pretože sa obe veci menia z iného dôvodu.**

- Keď zmeníš dizajn avatara (placeholder, veľkosť, class), dotkneš sa `AuthorAvatar`. `BlogAuthorResolver` a SQL sa nehnú.
- Keď zmeníš schému `users` (pridáš stĺpec), dotkneš sa `BlogAuthorResolver`. Markup avatara sa nehnú.
- Keď zmeníš štruktúru stránky, dotkneš sa `BlogArticleAction`. Obe služby sa nehnú.

Každá zmena má **presne jedno miesto**, kde sa robí. To je SRP v praxi.

## Smoke signál, z ktorého to vzniká

Ako by vyzeral kód bez tohto rozdelenia? (Toto je ilustrácia, nie presná
história ... v skutočnosti vznikli obe triedy presne týmto splitom.) Metóda,
ktorá by chcela byť `authorHtml()`, by vyzerala asi takto (skrátene):

```php
private function authorHtml(array $meta, BlogAuthorResolver $resolver, AuthorAvatar $avatar): string
{ // 1. načítaj fallback meno z front matter // 2. načítaj email z front matter // 3. spýtaj sa databázy na profil // 4. ak nie je email ... vráť escapované meno // 5. ak profil neexistuje ... vráť fallback // 6. vyrob HTML odkazu // 7. vyrob HTML avatara (súbor? placeholder?) // 8. poskladaj to dokopy
}
```

Osem krokov, dve témy (dáta z databázy + HTML). Keď by si chcel o rok zmeniť
placeholder, musel by si prečítať celých osem krokov, aj keď sa ťa týka len
krok 7. A keď by sa pokazil SQL, hľadal by si chybu medzi HTML reťazcami.
Presne toto rozdelil split na `BlogAuthorResolver` (dáta) a `AuthorAvatar`
(HTML) ... každá z dvoch metód má teraz jednu prácu:

```php
// BlogArticleAction ... vie len, že obe veci existujú
$resolver = new BlogAuthorResolver(DatabaseConnectionManager::instance());
$avatar = new AuthorAvatar(new LocalStorageProvider());
$metaLine = $this->metaLine($meta, $this->authorHtml($meta, $resolver, $avatar));
```

## Kedy je SRP prehnané?

A teraz to dôležité upozornenie, lebo každé pravidlo sa dá zneužiť. **SRP
neznamená „čo najviac malých metód“.** Toto nie je SRP:

```php
private function getTitle(array $meta): string
{ return (string) $meta['title'];
}
```

Toto je prekomplikovanie ... metóda, ktorá len vracia hodnotu, neodpovedá na
novú otázku, len presúva riadok. Rozdiel medzi dobrým a zlým rozdelením je
**dôvod zmeny**:

- Dobré rozdelenie: každá metóda/trieda sa mení z iného dôvodu.
- Zlé rozdelenie: rozsekáš jednu vec na desať kúskov a každá zmena sa ťa dotkne vo všetkých desiatich.

SRP tiež neznamená „každá zodpovednosť musí byť trieda“. Niekedy je jedna vec
taká malá, že ju reprezentuje konštanta alebo jednoduchá metóda ... o tom, kedy
je abstrakcia (rozhranie, trieda) predčasná a kedy má zmysel, bude presne
[diel 4](/blog/dry-vs-abstrakcia-pre-deti).

## Zhrnutie dielu 3

1. **Test jednou vetou** ... keď o metóde/triede povieš „robí A, B a C“, sú to tri metódy/triedy.
2. **SRP na metóde** ... `handle()` rozpráva príbeh, `metaLine()`, `tagChips()`, `coverImage()` robia prácu (chunky rule to vynucuje).
3. **SRP na triede** ... `BlogAuthorResolver` rieši len dáta, `AuthorAvatar` len markup, `BlogArticleAction` len kompozíciu. Každá zmena má jedno miesto.
4. **Dôvod zmeny je meradlo** ... nie počet riadkov. Rozdeľuj tam, kde sa veci menia z iných dôvodov, nie aby si mal veľa malých metód.
5. **Odkaz na ďalší diel** ... keď cítiš pokušenie „to by som extrahoval do spoločnej triedy“, príď na [diel 4](/blog/dry-vs-abstrakcia-pre-deti): DRY áno, predčasná abstrakcia nie.

## Zdroje

- [PHP Manual](https://www.php.net/manual/) — oficiálna PHP dokumentácia
- [PHP-FIG — PSR Standards](https://www.php-fig.org/) — PHP štandardy
- [Nativa Minimal Documentation](/docs/getting-started) — oficiálny návod
- [Web.dev — Performance](https://web.dev/performance) — Core Web Vitals
- [Lighthouse Documentation](https://developer.chrome.com/docs/lighthouse/) — Lighthouse nástroj
