---
title: "Performance pre deti ... diel 4: keď LCP nie je obrázok (textový LCP)"
date: "2026-08-13"
version: "1.0.1"
tags: ["performance", "lcp", "text", "ttf", "fonts", "lighthouse"]
excerpt: "Diel 4: všetky tie obrázkové triky z predošlých dielov sú zbytočné, keď stránka žiadny obrázok nemá. Na login stránke je LCP text ... a meria sa úplne inak. Ukážeme si TTFB, FOIT, font-display: swap a prečo je textový LCP najrýchlejšia vec na webe."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architecture"
translation: "text-lcp"
published: true
---

> Toto je diel 4 série **Performance pre deti**. V [dieli 1](/blog/performance-pre-deti)
> sme opravili LCP obrázky na indexe, v [dieli 2](/blog/performance-pre-deti-diel-2-cover-lcp)
> na článkovej stránke, v [dieli 3](/blog/performance-pre-deti-diel-3-lighthouse-detektiv)
> sme sa naučili čítať Lighthouse kriticky. Dnes si ukážeme opačný prípad:
> stránku, ktorá **nemá žiadny obrázok**. Čo je tam LCP? A prečo to
> znamená, že polovica trikov z predošlých dielov je zrazu zbytočná?

Stránka bez obrázku. Login formulár. Žiadne `img`, žiadne `video`. Čo je LCP? A prečo to znamená, že polovica trikov z predchádzajúcich dielov zrazu nefunguje?

## Stránka bez obrázkov: login

V tomto repo sú dve stránky, ktoré nemajú jediný `<img>`: `/login`
a `/register`. Žiadny hero, žiadny cover, žiadna karta. Iba nadpis,
formulár a pár slov. Čo je tam LCP?

Spustili sme Lighthouse a výsledok bol prekvapivo nudný:

| Metrika | Hodnota |
|---|---|
| Performance | **100** |
| LCP | **0,2 s** |
| FCP | **0,2 s** |
| Veľkosť HTML | 12 KiB |
| `lcp-discovery-insight` | **N/A** (nie je obrázok) |

A LCP element? Je ním dokonca aj **text, nie nadpis** ... `auth-footnote`,
malá poznámka pod formulárom. Na stránke s jediným boxom a formulárom je
„najväčším“ vykresleným obsahom obyčajný odsek. LCP 0,2 s znamená:
text sa vykreslil skôr, než by sa stihol stiahnuť akýkoľvek obrázok.

## Text LCP sa meria úplne inak

Tu je kľúčová myšlienka dielu: **obrázkový LCP čaká na sieťový request,
textový LCP čaká na nič.** Text je priamo v HTML. Prehliadač ho môže
vykresliť hneď, ako HTML dorazí ... nepotrebuje žiadny ďalší súbor.

To znamená, že textový LCP je funkciou troch vecí:

1. **TTFB (Time To First Byte)** ... ako rýchlo server pošle prvý bajt
   HTML. Toto je teraz tvoj najväčší pákový efekt: rýchla odpoveď
   servera = rýchly textový LCP.
2. **Rendering-blocking CSS** ... ak je štýl v externom súbore, ktorý
   blokuje vykreslenie, text počká, kým CSS nepríde. Riešenie: kritické
   CSS inline priamo v HTML (toto repo to robí ... pozri
   `FrontendLayout`).
3. **Web fonty** ... a tu je najzákernejší diel skladačky.

## FOIT: keď font zadrží text

Všimol si si niekedy, ako sa ti stránka načíta a text je najprv
neviditeľný, potom „preskočí“ do iného fontu? To je **FOIT** ... *flash of
invisible text*.

Keď má nadpis (alebo odsek) nastavený web font, ktorý sa sťahuje,
prehliadač stojí pred dilemou: „Mám ukázať text hneď v systémovom fonte,
alebo počkať na web font, aby vyzeral presne ako má design?“

V predvolenom nastavení veľa prehliadačov čaká ... a počas čakania je
text **neviditeľný**. Pre textový LCP je to katastrofa: LCP element je
v DOM-e, ale nevykreslil sa, lebo čaká na font. Lighthouse meria LCP
ako **viditeľný** paint, takže textový LCP sa posúva na čas, kedy font
dorazí.

Riešenie je `font-display`:

```css
@font-face {
    font-family: 'MojaPísma';
    src: url('/fonts/moja-pisma.woff2') format('woff2');
    font-display: swap;  /* ukáž text hneď v systémovom fonte,
                            vymeň ho, keď príde web font */
}
```

- `font-display: swap` ... text sa vykreslí okamžite v záložnom fonte
  (FOUT ... *flash of unstyled text*), web font sa vymení, keď dorazí.
  LCP je rýchly, text je čitateľný hneď.
- `font-display: block` (alebo default) ... text čaká na font, LCP
  trpí.
- `font-display: optional` ... ak font nepríde veľmi rýchlo, browser ho
  úplne vzdá a nechá systémový. Extrémne rýchle LCP, ale web font sa
  môže nezobraziť vôbec.

V tomto repo je to jednoduché: nepoužívame žiadne web fonty, len
systémové (`system-ui`). Preto textový LCP na `/login` nemá čo blokovať
a je 0,2 s. Ale keby sme font pridali bez `font-display: swap`, táto
stránka by sa zrazu spomalila ... bez jediného nového obrázka.

## Prečo lcp-discovery-insight zmizol

Všimni si v tabuľke `lcp-discovery-insight: N/A`. V predošlých dieloch
nám tento audit vravel „LCP obrázok má byť eager + fetchpriority“.
Na login stránke **žiadny obrázok nie je**, takže audit nemá čo povedať
... nie je fail, je **ne-aplikovateľný**. Lighthouse vie rozlíšiť
„nezvládol si to“ od „netýka sa to tejto stránky“.

To je presne to, o čom bol [diel 3](/blog/performance-pre-deti-diel-3-lighthouse-detektiv):
čítať audit podľa kontextu. `fetchpriority="high"` na texte nedáva
zmysel (text je v HTML hneď, nie je čo priorizovať). Pridať ho len
preto, aby audit nehneval, by bolo opravovanie neexistujúceho problému.

## Rovnaký vzor, iný nástroj

V [dieli 2](/blog/performance-pre-deti-diel-2-cover-lcp) sme si
povedali, že každá stránka má vlastný LCP. Teraz to vidíme naplno:

| Stránka | LCP element | Čo ho urýchli |
|---|---|---|
| `/blog` | cover prvej karty (obrázok) | `eager` + `fetchpriority`, srcset |
| `/blog/{slug}` | cover článku (obrázok) | `fetchpriority="high"` |
| `/` (home) | hero (obrázok) | `eager` + `fetchpriority`, srcset |
| `/login` | text (poznámka pod formulárom) | rýchly TTFB, inline CSS, žiadne blokujúce fonty |

Štyri stránky, štyri LCP elementy, dva úplne odlišné súbory nástrojov.
Obrázkové triky na textovej stránke nič nerobia ... a textové triky na
obrázkovej stránke tiež nie. **LCP sa nedá vyriešiť raz; treba ho merať
na každej šablóne.**

## Ako zrýchliť textový LCP

1. **Zrýchli TTFB** ... cachuj, čo sa dá, minimalizuj prácu servera.
   Text čaká na prvý bajt viac než na čokoľvek iné.
2. **Inline kritické CSS** ... text sa nevykreslí, kým nepríde
   rendering-blocking CSS. Kritické štýly v `<head>` inline, zvyšok
   načítaj neskôr.
3. **`font-display: swap`** ... web font nesmie držať text ako rukojemníka.
   A najrýchlejší font je ten, ktorý nemusíš sťahovať: systémový.
4. **Nemiešaj nástroje** ... nepridávaj `fetchpriority` na text
   a nesťahuj srcset pre stránku bez obrázkov. Kontext rozhoduje.

## Pravidlo bez testu sa nedodržiava

Textový LCP je v tomto repo v podstate „zadarmo“ ... systémové fonty,
inline CSS, malé stránky. Ale aj tak je tu test, ktorý stráži, že
auth stránky nemajú čo blokovať vykreslenie: `FrontendLayout` testy
kontrolujú, že kritické CSS je inline (nie externý render-blocking
súbor), a `BuildCssAuditTest` overuje, že built CSS je validný a
self-hosted. Žiadny web font = žiadny FOIT = textový LCP na max.

## Čo si z toho odniesť

1. **Textový LCP nečaká na sieť** ... je priamo úmerný TTFB a
   rendering-blocking CSS.
2. **FOIT je nepriateľ textu** ... web font bez `font-display: swap`
   drží text neviditeľný a LCP na mieste.
3. **N/A ≠ FAIL** ... keď audit nie je aplikovateľný, nerieš ho.
   Nepridávaj `fetchpriority` na text.
4. **Štyri stránky, dva svety** ... obrázkové triky vs textové triky.
   LCP sa meria na každej šablóne zvlášť.
5. **Najrýchlejší font je žiadny font** ... systémové fonty sú LCP
   priateľ číslo jedna.

Týmto sme uzavreli prvú „zákazku“ série: obrázkový LCP (diely 1–2),
kritické čítanie Lighthouse (diel 3) a textový LCP (diel 4). V záverečnom
dieli série zmeníme tému: rýchly web sme postavili ... a teraz si
ukážeme, čo s návštevnosťou, ktorú prinesie. [Diel 5](/blog/performance-pre-deti-diel-5-members-only-biznis)
je o members-only bonusových článkoch ako biznis vzore.


→ [Performance diel 1](/blog/performance-pre-deti): LCP, srcset a prečo sa obrázky nesťahujú zbytočne veľké
→ [Performance diel 3](/blog/performance-pre-deti-diel-3-lighthouse-detektiv): čítaj Lighthouse report ako detektív
→ [Clean code pre deti](/blog/co-je-cisty-kod-pre-deti): ako písať kód, ktorý sa číta sám

## Zdroje

- [PHP Manual](https://www.php.net/manual/): oficiálna PHP dokumentácia
- [PHP-FIG, PSR Standards](https://www.php-fig.org/): PHP štandardy
- [Nativa Minimal Documentation](/docs/getting-started): oficiálny návod
- [Web.dev, Performance](https://web.dev/performance): Core Web Vitals
- [Lighthouse Documentation](https://developer.chrome.com/docs/lighthouse/): Lighthouse nástroj
