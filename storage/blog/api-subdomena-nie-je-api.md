---
title: "API na subdoméne ... adresa ešte nie je rozhranie"
date: "2026-11-26"
version: "1.0.0"
tags: ["nativa", "php", "rest", "api", "nasadenie", "seo", "bezpecnost"]
excerpt: "Subdoména api.responsive.sk už beží a vracia celý web. Zmeral som, čo na nej naozaj je, prečo to nie je API a kedy má subdoména zmysel."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architecture"
published: true
---

Otázka prišla v jednej vete a s hotovou adresou.
Máme na `responsive.sk` povoliť subdoménu `api`?

Krátka odpoveď je, že netreba nič povolovať.
Subdoména `api.responsive.sk` už existuje a odpovedá.

## Čo som nameral

Overil som ju zvonku a správa sa ako plnohodnotná kópia webu.
DNS ukazuje na `86.110.233.32`, teda na ten istý server ako hlavná doména.
Domov vráti 200 a rovnaký titulok.
`/health` vráti `healthy` a hlási 13 databáz.
`/sitemap.xml` vráti 200 a `/mark` presmeruje na `/login`.

| povrch | `responsive.sk` | `api.responsive.sk` |
|---|---|---|
| domov | 200 | 200, iný canonical |
| `/health` | healthy, 13 DB | healthy, 13 DB |
| `/sitemap.xml` | 200 | 200, vlastné `loc` |
| `/mark` | 302 na `/login` | 302 na `/login` |

To všetko znamená jediné.
Na subdoméne nebeží API, ale celý CMS.

## Prečo to nie je API

Kernel nerozlišuje hostiteľa pri smerovaní.
`Router` triedi akcie podľa cesty a `Host` používa len na absolútne adresy.
Kanonická adresa, sitemap a hreflang sa skladajú z `Host` hlavičky.
Keď je `TRUSTED_HOSTS` prázdne, je to presne tá hlavička, ktorú poslal klient.

Preto dnes existujú dve kanonické podoby toho istého webu.
`https://responsive.sk/` sa kanonizuje na seba.
`https://api.responsive.sk/` sa kanonizuje na seba.
Ani jeden z tých dvoch sitemapov vôbec nevie o existencii toho druhého.
Pre vyhľadávač to nie je API, ale duplicitný obsah.

Toto nie je chyba modulu.
Je to dôsledok toho, že adresa a rozhranie sú dve rôzne veci.
Subdoména pridáva len druhú adresu.

## Panel áno, subdoména nie

Panel, ktorý máš na mysli, je modul `RestApi` a sedí na `/mark/rest`.
Je to odchádzajúci klient, nie verejné rozhranie webu.
Operátor doň zapíše službu, resource a polia a panel tie vzdialené API číta.
Zápis je opt-in, token sa ukladá ako názov environmentálnej premennej
a nikdy nie jej samotná hodnota.
`SsrfGuard` pustí verejný host aj vtedy, keď je allowlist prázdny.
`RESTAPI_ALLOWED_HOSTS` je výnimka, ktorá dovolí aj súkromnú adresu.

Na registráciu existujúceho API teda subdoména netreba vôbec.
Server volá von, nie dovnútra.

## Kedy má subdoména zmysel

Subdoména má zmysel vtedy, keď chceš oddeliť hranicu.
Iný server, iné škálovanie, iné WAF pravidlá alebo verzia API, ktorá nesmie zdieľať cookies s webom.
Vtedy je to architektonické rozhodnutie, nie skratka.

Ak raz budeš chcieť vlastné API pre obsah, je jednoduchšie ho postaviť na ceste.
`/api/v1/articles` na hlavnej doméne zdedí TLS, session, logy aj rate limiting.
Nepotrebuje nový certifikát, nové CORS pravidlá ani druhý sitemap.
Cookie sú viazané na host, takže prihlásenie na `api.` by bola druhá session.

## Ak ju predsa necháš

Ak `api.` zostane, treba doplniť tri veci.
`TRUSTED_HOSTS` nastaviť na oba hosty, aby canonical nezávisel od requestu.
Na `api.` obmedziť HTML povrchy alebo presmerovať web na hlavnú doménu.
V sitemape nechať len jednu kanonickú podobu.

HSTS má `includeSubDomains`, takže subdoména musí mať platný certifikát.
Dnes ho má, ale je to záväzok pri každej ďalšej subdoméne.

Subdoména je adresa.
Rozhranie je kontrakt, ktorý niekto volá a ktorý má vlastné chyby, limity a verzie.
Zameniť jedno za druhé je najdrahšia časť celej otázky.

---

→ [Ako sme spravili sitemap](/blog/ako-sme-spravili-sitemap) ... kanonické adresy v praxi
→ [robots.txt, čo blokujeme](/blog/robots-txt-co-blokujeme) ... čo má a nemá vidieť vyhľadávač
→ [Nativa PHP Core](/blog/nativa-php-core) ... čo tento strom je

## Zdroje

- [MDN, Host](https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Host): hlavička, z ktorej sa skladajú absolútne adresy
- [RFC 9110, Host and :authority](https://httpwg.org/specs/rfc9110.html): normatívna definícia hostiteľa requestu
- [OWASP, Host Header Injection](https://owasp.org/www-project-web-security-testing-guide/): prečo canonical nesmie závisieť od klienta
- [MDN, CORS](https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS): prečo subdoména pridáva nové pravidlá
