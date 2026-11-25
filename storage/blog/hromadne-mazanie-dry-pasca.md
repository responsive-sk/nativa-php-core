---
title: "Hromadné mazanie, ktoré renderovalo dvakrát"
date: "2026-08-30"
version: "1.0.2"
tags: ["htmx", "dry", "bulk-actions", "refactoring", "clean-code"]
excerpt: "Postavili sme hromadné mazanie pre štyri admin tabuľky. Potom sme duplikovali každý renderer. Oprava bola jedna zdieľaná trieda na entitu — ale cesta k nej nás naučila niečo o HTMX a vlastníctve kódu."
author: "Evan McDan"
author_email: "evan.mcdan@example.test"
category: "Clean Code"
translation: "htmx-bulk-actions-dry-trap"
published: true
---

![Screenshot_2026-08-27_16-05-13.jpg](https://res.cloudinary.com/epithemic/image/upload/nativa-media/ne3ulmsddkg1dwb6cj8s)

Označiť všetko. Vymazať vybrané. Sledovať ako sa tabuľka aktualizuje bez reloadu.

To bol sľub. A fungovalo — na prvej tabuľke. Potom sme skopírovali renderovací kód na druhú. A tretiu. A štvrtú. Keď sme mali štyri identické table rendery, mali sme DRY porušenie, ktoré nás uhryzne zakaždým, keď niekto zmení hlavičku stĺpca.

## Nastavenie

Hromadné akcie v admin paneli nasledujú predvídateľný vzor. Každý riadok tabuľky dostane checkbox. „Označiť všetko“ checkbox žije v headeri. Keď sú položky označené, zobrazí sa plávajúci panel s tlačidlom „Vymazať vybrané“. Formulár POSTne na bulk endpoint, server vymaže položky a HTMX swapne aktualizovanú tabuľku späť na stránku.

Checkbox markup je jednoduchý:

```php
// BulkActions.php — jeden checkbox cell
public static function checkboxCell(string $id): string
{
    $escaped = AdminLayout::e($id);
    return '<td class="m-bulk__cell">'
        . '<input type="checkbox" name="ids[]" value="' . $escaped . '"'
        . ' class="m-bulk__check">'
        . '</td>';
}
```

Select-all a počítanie žije v JavaScripte:

```javascript
// Select all → označí každý riadok
selectAll.addEventListener('change', function() {
    var checks = document.querySelectorAll('.m-bulk__check:not(.m-bulk__select-all)');
    for (var i = 0; i < checks.length; i++) {
        checks[i].checked = selectAll.checked;
    }
    updateBulkBar();
});
```

Bulk bar sa zobrazí, keď `checked > 0`, a zmizne, keď `checked === 0`. Formulár submitne cez HTMX a server vracia aktualizovanú tabuľku. Čisté. Predvídateľné. Opakovateľné.

Potom sme to pridali na štyri tabuľky.

## Kaskáda

UsersAction dostal bulk checkboxes. RolesAction tiež. A PagesIndexAction. A ArticlesIndexAction. Každá akcia budovala vlastnú tabuľku — thead, tbody, riadky, bulk bar. Každá akcia mala vlastnú metódu `renderRow()` s trochu odlišnými stĺpcami.

Keď BulkDeleteAction potreboval prerenderovať tabuľku po vymazaní, mal dve možnosti: zavolať render metódu pôvodnej akcie, alebo duplikovať renderovací kód. Zvolili sme duplikáciu. Rýchlejší release. Žiadne cross-module importy. Žiadne refactoring riziko.

Asi na desať minút.

Problém sa ukázal, keď sme zmenili header Users tabuľky. Pridali sme stĺpec „Status“ do pôvodnej akcie, ale zabudli sme aktualizovať `renderUsersTable()` v BulkDeleteAction. Dve tabuľky vyzerali inak podľa toho, ako ste sa na ne dostali.

To bol symptóm. Choroba bola hlbšia.

BulkDeleteAction mal 290 riadkov. Polovica bola copy-paste renderovania z štyroch rôznych akcií. Zakaždým, keď niekto zmenil badge, pridal stĺpec, alebo upravil CSS class, musel si pamätať aktualizovať kópiu v BulkDeleteAction tiež.

Nebude. Nikto to nerobí.

## Oprava

Riešenie bola jedna zdieľaná trieda na entitu. Nie zdieľané rozhranie — konkrétna trieda v module, ktorý vlastní tabuľku.

```
UsersTableRenderer   →  Users modul  (používa UsersAction + BulkDeleteAction)
RolesTableRenderer   →  Users modul  (používa RolesAction + BulkDeleteAction)
PagesTableRenderer   →  Pages modul  (používa PagesIndexAction + BulkDeleteAction)
ArticlesTableRenderer → Blog modul   (používa ArticlesIndexAction + BulkDeleteAction)
```

Každý renderer má dve metódy:

```php
// table() — len <table> HTML (pre akcie s pagination/countInfo)
public static function table(array $pages, string $locale): string

// render() — kompletný bulk bar + wrapper + table (pre BulkDeleteAction re-render)
public static function render(array $pages, string $locale): string
```

Pôvodné akcie volajú `table()` a wrappnú vlastný search input, count info a pagination. BulkDeleteAction volá `render()` a dostane kompletný balík.

Jeden zdroj pravdy. Jedno miesto na zmenu hlavičky stĺpca. Jedno miesto na pridanie badge.

BulkDeleteAction klesol z 290 na 154 riadkov. Renderovanie tabuľky kleslo zo štyroch kópií na jednu.

## Čo HTMX očakáva

HTMX s `outerHTML` swapom nahradí target element zodpovedajúcim elementom z odpovede. Ak odpoveď obsahuje elementy mimo targetu, zahodia sa — ale len ak ich server vráti.

Bulk formulár cieli na `#users-content`. Bulk bar žije mimo tento target. Po submite HTMX swapne len `#users-content` s novou tabuľkou. Bulk bar zostáva v DOM so stale stavom.

Riešime to cez `htmx:afterSwap` listener, ktorý resetuje počet:

```javascript
document.body.addEventListener('htmx:afterSwap', function() {
    updateBulkBar();
});
```

Po každom swape sa počet prepočíta z aktuálnych checkboxov v DOM. Ak server vrátil čerstvú tabuľku (0 označených), počet sa resetuje na nula a bar zmizne.

Poučenie: HTMX partial odpoveď by mala obsahovať len target element. Ak potrebujete aktualizovať okolité elementy, urobte to JavaScriptom po swape.

## Transakcia

Prvá verzia hromadného mazania spúšťala každé vymazanie nezávisle. Vymazať user 1. Vymazať user 2. Vymazať user 3. Ak user 3 zlyhal (posledný admin), user 1 a 2 sú už preč.

Pre DB-backed entity sme zabalili loop do UnitOfWork transakcie:

```php
$pdo = DatabaseConnectionManager::instance()->connection('users');
$uow = new UnitOfWork($pdo);
$uow->transaction(function (PDO $pdo) use ($entity, $ids, &$deleted): void {
    foreach ($ids as $id) {
        if ($this->deleteItem($entity, (string) $id, '')) {
            $deleted++;
        }
    }
});
```

Všetky vymazania úspešné, alebo všetky rollback. Žiadny čiastočný stav.

Pre filesystem entity (pages, articles) nie je DB transakcia. Zlyhanie vymazania uprostred loopu zanechá niektoré súbory vymazané a iné nie. To je inherentné obmedzenie filesystem operácií — nemôžete rollbacknúť `unlink()`.

Rozhodnutie bolo explicitné: DB entity dostávajú atomicitu. Filesystem entity dostávajú best-effort. Kód dokumentuje, ktoré je ktoré.

## Čo sme sa naučili

DRY pasca v bulk akciách je jemná, pretože duplikácia sa deje postupne. Pridáte bulk support na jednu tabuľku. Funguje. Skopírujete vzor na druhú. Funguje. Pri štvrtej tabuľke je renderovací kód všade a zmena stĺpca znamená úpravu piatich súborov.

Zdieľané rendery to riešia, ale vyžadujú premýšľanie o vlastníctve. Renderer žije v module, ktorý vlastní entitu. Akcia volá renderer. BulkDeleteAction volá ten istý renderer. Jedna pravda, viac spotrebiteľov.

Factory pattern tiež funguje. `new BlogCrudService()` rozosiate po akciách vytvára neviditeľné väzby. `CardboardServices::blogCrud()` robí závislosť explicitnou a cacheovateľnou.

HTMX hromadné akcie na frontende pôsobia bez námahy. Zložitosť žije v serverovej odpovedi — vrátiť presne ten správny HTML, spracovať transakciu a udržať renderovací kód na jednom mieste.

Table renderer nie je framework. Je to funkcia, ktorá vracia string. Ale keď ten string je jediný zdroj pravdy o tom, ako tabuľka vyzerá, zaslúži si svoje miesto.

→ [Kliknite stránku 3 ... a pager sa zdvojnásobil](/blog/htmx-pager-duplication) — prvý HTMX pagination bug
→ [MoonShine Features + HTMX](/blog/moonshine-htmx-features) — toast notifikácie, live search, form submit
→ [DRY vs Abstraction](/blog/dry-vs-abstraction) — jeden zdroj pravdy, žiadne rozhrania pre rozhrania

## Zdroje

- [PHP Manual](https://www.php.net/manual/) — oficiálna PHP dokumentácia
- [PHP-FIG — PSR Standards](https://www.php-fig.org/) — PHP štandardy
- [OWASP Cheat Sheet Series](https://cheatsheetseries.owasp.org/) — bezpečnostné best practices
- [Nativa Minimal Documentation](/docs/getting-started) — oficiálny návod
- [W3C — Web Standards](https://www.w3.org/standards/) — web štandardy
