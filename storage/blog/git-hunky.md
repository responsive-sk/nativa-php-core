---
title: "Git hunks ... prečo git nedelí diff na riadky, ale na bloky"
date: "2026-08-14"
version: "1.0.3"
tags: ["git", "workflow", "tooling"]
excerpt: "Čo je to hunk, prečo ho git používa namiesto obyčajného zoznamu riadkov a ako sa s ním pracuje ... git diff, git add -p a jeden reálny prípad, keď nás hunk prinútil rozmýšľať inak."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Architecture"
views: "0"
translation: "git-hunks"
published: true
---

Všetci poznáme `git diff` ... ukáže, čo sa zmenilo. Ale keď príde na
jemnejšie ovládanie gitu, narazíme na slovo, ktoré sa tvári ako anglické
„chunk“ (kus), a predsa je to niečo iné: **hunk**. Čo presne to je, prečo
to git používa a prečo na tom záleží pri code review aj pri rozdeľovaní
commitov?

## Riadky vs. bloky

Keby si diff predstavoval naivne, bola by to tabuľka: „riadok 12 zmazaný,
riadok 13 pridaný, riadok 40 zmenený…“. Git to nerobí. Namiesto toho
zoskupí zmenené riadky do **súvislých blokov** ... a každý taký blok sa
volá **hunk**.

```diff
@@ -24,6 +24,8 @@ ← hlavička hunku
 function hello() { echo "Ahoj"; }
+echo "Ako sa máš?";
+echo "Dnes je pekný deň.";
 echo "Zbohom"; }
```

Hlavička `@@ -24,6 +24,8 @@` je kompaktná mapa zmeny:

- `-24,6` ... v **starom** súbore blok začína na riadku 24 a má 6 riadkov
- `+24,8` ... v **novom** súbore blok začína na riadku 24 a má 8 riadkov (pribudli 2 riadky)
- riadky bez znamienka sú **kontext** ... nezmenený okolitý kód, ktorý git pridal do diff-u, aby bolo vidieť, kde zmena žije.

Hunk teda nie je len „kus dát“ (to by bol chunk) ani „háčik“ (to je
hook ... skript spúšťaný pri udalostiach ako `pre-commit`). Hunk je
**ohraničená zmena s kontextom** ... a práve kontext je dôvod, prečo git
delí diff týmto spôsobom.

## Prečo kontext robí diff čitateľným

Predstav si súbor, kde niekto zmenil funkciu na riadku 300. Bez kontextu
by diff ukázal „riadok 300: zmenený“ ... a ty by si musel otvárať súbor,
aby si zistil, čo to je. S kontextom vidíš funkciu aj s jej
signatúrou:

```diff
@@ -295,7 +295,7 @@
-fun sendEmail(to, subject, body) {
+fun sendEmail(to, subject, body, attachments = []) { mailer.deliver(to, subject, body); }
```

Vidíš, čo sa zmenilo, aj **kde** to žije ... bez otvárania súboru. Kontext
je presne to, čo odlišuje hunk od obyčajného zoznamu riadkov.

## Hunk v praxi: git add -p

Najznámejšie miesto, kde hunks stretneš, je interaktívne stagovanie:

```bash
git add -p
```

Git prejde zmenené súbory hunk po hunku a pýta sa:

```
Stage this hunk? [y,n,q,a,d,e,?]
```

- `y` ... staguj tento hunk
- `n` ... preskoč ho
- `s` ... rozdeľ hunk na menšie (ak je medzi zmenami nezmenený kontext)
- `e` ... ručne uprav hunk
- `?` ... nápoveda Vďaka tomu môžeš z jedného súboru s dvoma rôznymi zmenami (povedzme
bugfix + refactoring) spraviť dva čisté commity ... bez toho, aby si
manuálne kopíroval riadky.

## Keď je hunk príliš veľký

Hunka sa nedá rozdeliť vždy. Ak sú dve zmeny **hneď vedľa seba** (bez
nezmeneného kontextu medzi nimi), git ich zlúči do jedného hunku a
`git add -p` ponúkne `e` (edit) namiesto `s` (split). Vtedy máš dve
možnosti:

1. **Ručne upraviť hunk** (`e`) ... orežeš kontext a necháš len tú časť, ktorú chceš stagovať.
2. **Manuálne naniesť časť zmeny** ... vrátiť súbor do pôvodného stavu, aplikovať len jednu časť a commitnúť postupne (presne to sme dnes robili pri rozdeľovaní archivácie: `git restore` + `awk` na jednu časť hunku, commit, potom druhá časť, commit).

Dôležité: `git add <súbor>` staguje **celý súbor**, nie časť hunku.

Ak
chceš oddeliť dve zmeny v jednom súbore, `git add -p` je ten správny
nástroj ... a ak hunky nejdú rozdeliť, siahni po variante 2.

## Prečo to riešiť

Hunky nie sú teória ... ovplyvňujú, ako robíš code review aj ako píšeš
commit history:

| Prístup | Výsledok |
|---|---|
| `git add <súbor>` | Jeden commit = všetko, čo je v súbore (aj nesúvisiace zmeny) |
| `git add -p` | Jeden commit = jedna logická zmena, aj z toho istého súboru |
| Ručný split (`restore` + apply) | Funguje aj tam, kde hunk nejde rozdeliť |

A pri code review? Keď niekto pošle PR, ktoré mení „len pár riadkov" na
piatich miestach, dívaš sa presne na **hunky** ... zmeny s kontextom.
GitHub a GitLab to robia za teba. Rozumieť im znamená rozumieť tomu, čo kontroluješ.

## Zhrnutie

- **Hunk** = súvislý blok zmenených riadkov s kontextom (nie chunk, nie hook)
- Hlavička `@@ -starý,počet +nový,počet @@` hovorí, kde blok začína a koľko riadkov má
- Kontext robí diff čitateľný ... vidíš, kde zmena žije
- `git add -p` staguje hunk po hunku → čisté commity z jedného súboru
- Keď hunk nejde rozdeliť (zmeny hneď vedľa seba), uprav ho ručne alebo rozdeľ commit manuálne Nabudúce, keď uvidíš `@@ -1324,3 +1324,104 @@` v `git diff`, už to budeš
vedieť prečítať: „v starom súbore blok na riadku 1324 (3 riadky), v novom
na 1324 (104 riadkov) ... a git to považuje za jednu zmenu."

→ [OpenSpec pre deti](/blog/co-je-openspec-pre-deti) — prečo sa zmeny nepíšu priamo do spec
→ [Anatómia change](/blog/anatomia-change-pre-deti) — päť súborov, päť otázok
→ [Flow a ADR](/blog/flow-a-adr-starter-kitu) — mindset za starter kitom

## Zdroje

- [Git — git-diff Documentation](https://git-scm.com/docs/git-diff) — oficiálna diff dokumentácia
- [Git — git-add Documentation](https://git-scm.com/docs/git-add) — `git add -p` interaktívne stagovanie
- [Pro Git Book — Distributed Git](https://git-scm.com/book/en/v2/Distributed-Git-Distributed-Workflows) — distribuované workflowy
- [GitHub — Interactive Staging](https://docs.github.com/en/get-started/using-git/about-git#interactive-staging) — interaktívne staging tutorial
- [Atlassian — Git Diff Tool](https://www.atlassian.com/git/tutorials/difftool) — diff nástroje a vizualizácia
