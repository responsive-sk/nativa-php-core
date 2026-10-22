---
title: "Herdr workspaces — ako držať viac repozitárov v jednej session"
date: "2026-10-21"
tags: ["herdr", "terminal", "workspaces", "worktree", "agenti"]
excerpt: "Workspace, tab a pane nie sú tri názvy pre to isté. Zmerané na živej session: čo ktorá vrstva drží, kedy je správna odpoveď nový workspace a kedy worktree, ako vyzerá hub s viacerými repozitármi — a kde sa pane cwd rozíde s metadátami workspace."
author: "nativa.dev@example.test"
author_email: "nativa.dev@example.test"
category: "Nástroje"
published: true
---

## Časť I — Otázka, ktorá vyzerala ako áno/nie

Prišla veta, ktorá sa dá zodpovedať jedným slovom a pritom nijako nepomôcť:

> „mozeme pracovat v herdr na viacerych repo?"

Odpoveď „áno" je pravdivá a nepoužiteľná. Herdr nemá jednu úroveň, na ktorej by
„viac repozitárov" znamenalo jednu vec. Má tri — a každá z nich rieši iný problém,
s inými dôsledkami pre to, čo sa deje s git indexom, s pracovným stromom a s
kontextom agenta, ktorý v tom termináli beží.

Pretože išlo o otázku o stave, nie o názor, odpoveď sa dala odmerať. Toto je
zápis merania a pravidiel, ktoré z neho vyplynuli — nie návod na klávesové
skratky.

---

## Časť II — Tri slová, ktoré Herdr rozlišuje

Herdr je terminálový správca pracovných priestorov pre kódovacích agentov.
Topológia má tri úrovne a každá má vlastné ID:

```
workspace   w6            najvyššia úroveň, má cwd a metadáta worktree
  tab       w6:t2         skupina panov v rámci workspace
    pane    w6:p8         skutočný terminál, má vlastný cwd aj proces
```

| úroveň | čo drží | vlastné cwd | čo sa v nej vytvára |
|---|---|---|---|
| workspace | najvyššia jednotka, metadáta worktree | áno | `workspace create` |
| tab | vizuálna skupina panov | áno | `tab create` |
| pane | skutočný terminál a proces | áno | `pane split` |

ID sú **nepriehľadné a stabilné**: zatvorené ID sa už nikdy nepoužije a pane
presunutý do iného workspace dostane nové, workspace-kvalifikované ID. Preto sa
identifikátory nehádajú — čítajú sa z odpovede príkazu, ktorý daný objekt
vytvoril (`workspace create` vráti `.result.workspace`, `.result.tab` a
`.result.root_pane`; `pane split` vráti `.result.pane`).

Každý spravovaný pane dostane do prostredia kontext volajúceho:

```bash
printf '%s\n' "$HERDR_WORKSPACE_ID" "$HERDR_TAB_ID" "$HERDR_PANE_ID"
# w6
# w6:t2
# w6:p8
```

To je dôvod, prečo sa `pane split` bez cieľa správa inak než väčšina ostatných
príkazov: keď je `HERDR_PANE_ID` dostupné, delí **volajúci** pane, nie ten,
ktorý má fokus v UI. Pri ostatných príkazoch môže `--current` zamieriť na
volajúci pane explicitne — a je to bezpečnejšie než spoliehať sa na to, kde má
užívateľ práve kurzor.

---

## Časť III — Čo bolo v session namerané

Pred akýmkoľvek presúvaním súborov alebo repozitárov sa vyplatí pozrieť, čo v session
naozaj je:

```bash
herdr workspace list
herdr tab list --workspace "$HERDR_WORKSPACE_ID"
herdr pane list  --workspace "$HERDR_WORKSPACE_ID"
herdr worktree list
```

Namerané (herdr 0.9.5, jedna session, jeden klient):

```
workspace   w6   label "nativa-php-core"   tab_count 1   pane_count 3
  worktree  checkout_path /home/evan/dev/06/nativa-minimal
            repo_root     /home/evan/dev/06/nativa-minimal
            is_linked_worktree false
  pany      w6:p8  cwd /home/evan/dev/06/nativa-php-core  (fokus, agent)
            w6:pB  cwd /home/evan/dev/06/nativa-php-core  (shell)
            w6:pC  cwd /home/evan/dev/06/nativa-php-core  (yay)
```

Dve veci na tom stoja za pozornosť, lebo vyzerajú ako rozpor a nie sú:

1. **Label a cwd hovoria `nativa-php-core`, metadáta worktree hovoria
   `nativa-minimal`.** Metadáta workspace sa odvodzujú z jedného repozitára a sú
   informatívne; pracovný adresár drží každý pane zvlášť. Keď sa pany rozídu do
   rôznych repozitárov, workspace-level `worktree` objekt prestane byť pravda o obsahu
   a zostane len stopou o tom, z ktorého repozitára workspace vznikol.
2. **Existujúci linked worktree bol zatvorený.** `herdr worktree list` ukázal
   dva checkouty toho istého repozitára:

```
/home/evan/dev/06/nativa-minimal                                          master                  open → w6
/home/evan/.herdr/worktrees/nativa-minimal/worktree-green-harbor-7663     worktree/green-harbor-7663   (zatvorený)
```

Worktree teda nie je exotika — Herdr si ich drží v `~/.herdr/worktrees/<repo>/`
a vie ich otvoriť aj zavrieť bez toho, aby sa čokoľvek stratilo.

---

## Časť IV — Tri úrovne, ako sa dostať k druhému repozitáru

### 1. Workspace na repozitár

Najčistejšia odpoveď, keď má ísť o paralelnú prácu. Každý workspace má vlastný
pane, vlastný cwd a vlastného agenta:

```bash
herdr workspace create --cwd /home/evan/dev/06/nativa-minimal --label nativa-minimal --focus
herdr workspace create --cwd /home/evan/dev/06/boson           --label boson          --no-focus
```

`--focus` prepne užívateľa do nového workspace; `--no-focus` ho nechá tam, kde
je. Pri zakladaní na pozadí je `--no-focus` správna voľba vždy — fokus patrí
užívateľovi, nie automatizácii.

### 2. Tab s vlastným cwd

Keď majú byť oba repozitáre v jednom okne, ale oddelene:

```bash
herdr tab create --workspace w6 --cwd /home/evan/dev/06/ui --label ui
```

Tab je vizuálna skupina. Nedáva oddelený git kontext ani oddeleného agenta —
dáva poriadok v panoch.

### 3. Pane s vlastným cwd

Najlacnejšie a najčastejšie. Jeden panel vedľa druhého, každý v inom repozitári:

```bash
herdr pane split --current --direction right --cwd /home/evan/dev/06/boson --no-focus
```

Geometrické pravidlo, ktoré sa vyplatí dodržať: **široký pane deliť doprava,
úzky alebo vysoký dole.** Opakované delenie tým istým smerom vyrába stĺpce, v
ktorých sa nedá čítať diff ani log.

---

## Časť V — Hub: jeden workspace, viac repozitárov

Ďalšia otázka prišla hneď po tom, ako hub vznikol: môže taký workspace obsahovať
viac rôznych repozitárov, alebo je to len adresár, v ktorom sa nič gitové nedeje?

Môže — a je to presne ten prípad, na ktorý hub existuje. Workspace nemá jednu rolu repozitára; je to najvyššia jednotka s vlastným cwd, a **každý pane v ňom má cwd
vlastný**. Meranie z tej istej session:

```
w7  label dev10   cwd /home/evan/dev/10     (nadradený adresár, sám nie je repozitár)
  w7:p1           cwd /home/evan/dev/10

dev/10/
  resp-blog        git repozitár, vetva main
  boson            bez .git
  FontGenerator    bez .git
  font-subsetter   bez .git
  marko            bez .git
```

Pane v hube sa do repozitára dostane obyčajným `cd`, alebo sa rovno založí s cwd v
ňom:

```bash
herdr pane split --current --direction right \
  --cwd /home/evan/dev/10/resp-blog --no-focus

herdr tab create --workspace w7 --cwd /home/evan/dev/10/resp-blog --label resp-blog
```

Každý taký pane má vlastný shell, vlastný git kontext a môže v ňom bežať vlastný
agent. Hub je teda legitímny spôsob, ako mať päť repozitárov v jednom okne.

Cena za to je jedna vec a je merateľná: **hub nemá metadáta repozitára.**

```bash
herdr workspace get w7
# ... "workspace": {"label": "dev10", ...}      ← žiadny "worktree" kľúč
```

Pre porovnanie, workspace založený priamo v repozitári (časť III) má `worktree` objekt
s `repo_root`, `repo_key` a `is_linked_worktree`. Hub nie — nemá z čoho. A git
pomocníci sa podľa toho správajú:

```bash
# pane je v /home/evan/dev/10, čo nie je git work tree
herdr worktree list
# {"error":{"code":"not_git_worktree",
#   "message":"Herdr worktree actions require a workspace inside a Git work tree"}}

# po cd do repozitára tá istá otázka funguje a nájde zdrojový repozitár
herdr pane run w7:p1 "cd /home/evan/dev/10/resp-blog"
herdr worktree list
# {"source":{"repo_name":"resp-blog", "source_workspace_id":"w7"}, ...}
```

Z toho vyplývajú tri pravidlá pre hub:

| pravidlo | dôsledok |
|---|---|
| git pomocníci čítajú pane, nie workspace | `worktree create`/`list` pušťaj s `--cwd` do konkrétneho repozitára |
| hub nemá predvolený repozitár | `herdr worktree create --workspace w7` nemá z čoho vychádzať |
| pany v tom istom repozitári zdieľajú index | izoláciu dáva worktree, nie druhý pane |

A jedna vec, ktorá platí bez ohľadu na to, či je workspace hub alebo repozitár: keď sa
pany rozídu do rôznych repozitárov, workspace-level metadáta prestanú byť pravdou o
obsahu. Hub je čitateľný vtedy, keď tie repozitáre k sebe patria; keď spolu nesúvisia,
workspace na repozitár je zrozumiteľnejší.

---

## Časť VI — Worktree: druhý checkout toho istého repozitára

Workspace rieší „iný repozitár". Worktree rieši „iná vetva toho istého repozitára bez
rozbitia hlavného checkoutu":

```bash
herdr worktree create --cwd /home/evan/dev/06/nativa-minimal \
  --branch worktree/moja-feature --no-focus

herdr worktree open --path /home/evan/.herdr/worktrees/nativa-minimal/worktree-green-harbor-7663
```

Rozdiel oproti dvom panom v tom istom checkoute je zásadný a je to jediný
rozdiel, na ktorom naozaj záleží: **dva pany v jednom checkoute zdieľajú jeden
git index a jeden pracovný strom.** Dva worktree nie. Preto:

- dva agenti v tom istom checkoute na tej istej vetve = súťaž o index;
- dva agenti v dvoch worktree = dve nezávislé vetvy, žiadna kolízia;
- dva pany v tom istom checkoute sú v poriadku len na **read-only** prácu —
  testy, hľadanie, čítanie logov.

---

## Časť VII — Agenti: jeden pane, jeden agent

`agent start` nezakladá layout. Vyžaduje existujúci pane, ktorý je na
interaktívnom shell prompte, a v ňom rozpozná zadaný typ agenta:

```bash
herdr pane split --current --direction right --cwd "$PWD" --no-focus
# prečítaj nové ID z .result.pane.pane_id
herdr agent start reviewer --kind codex --pane w6:pD
herdr agent prompt reviewer "Zhrň zmeny v tomto worktree." --wait --timeout 120000
```

Podporovaných kindov je dvadsaťštyri (`pi`, `claude`, `codex`, `gemini`,
`cursor`, `devin`, `agy`, `cline`, `omp`, `mastracode`, `opencode`, `copilot`,
`kimi`, `kiro`, `droid`, `amp`, `grok`, `hermes`, `kilo`, `qodercli`, `qwen`,
`letta`, `maki`, `muse`) a meno agenta musí sedieť na
`[a-z][a-z0-9_-]{0,31}` a byť unikátne medzi živými agentmi.

Stavy, s ktorými sa dá počítať: `idle`, `working`, `blocked`, `done`,
`unknown`. Dve upozornenia, ktoré vyzerajú ako formalita a nie sú:

- `blocked` znamená rozpoznaný approval alebo otázku. Na taký dialog sa
  neodpovedá naslepo — najprv `agent get` a `agent read`, potom rozhodnutie.
- `unknown` neznamená hotovo. Znamená, že Herdr nevie stav klasifikovať.

Preto sa `agent prompt` s `--wait` používa ako štandard (čaká na prvý ustálený
stav), kým `--until blocked` je vyhradené pre workflow, kde sa na otázku
naozaj čaká.

---

## Časť VIII — Rozhodovacia tabuľka

| chcem | primitívum |
|---|---|
| iný repozitár, paralelná práca | workspace na repozitár |
| viac repozitárov v jednom okne | hub workspace + pane/tab s `--cwd` |
| iná vetva na tom istom repozitári | worktree |
| druhý pohľad na ten istý checkout | pane split alebo tab |
| len čítať výstup, nič nevytvárať | pane read |
| spustiť príkaz a počkať na jeho výstup | pane run + pane wait-output |
| agent, ktorý má stav a dá sa naň čakať | agent start + agent prompt --wait |

Tretí riadok je jediný, kde si treba uvedomiť cenu: dva pany nad jedným
checkoutom sú bezplatné na čítanie a drahé na zápis.

---

## Časť IX — Pasce, ktoré nie sú chyby

**Holé `herdr` neotváraj na prieskum.** Spustí alebo pripojí TUI. Objavovanie
začína na `herdr --help` a pokračuje skupinou bez podpríkazu (`herdr workspace`,
`herdr pane`, `herdr agent`).

**Mutujúci príkaz sa netestuje vynechaním argumentov.** `herdr workspace create`
je validný aj bez prepínačov — a naozaj založí workspace. „Skúsim, čo to
povie" tu znamená „urobil som to".

**Metadáta workspace nie sú cwd panov.** Nameraný príklad z časti III to
ukazuje priamo: workspace tvrdí `nativa-minimal`, všetky tri pany sú v
`nativa-php-core`. Pri miešaní repozitárov v jednom workspace sú tie metadáta len
stopa o pôvode — a hub (časť V) ich nemá vôbec, takže git pomocníci sa riadia
pane cwd, nie workspace.

**Agent edituje to, čo vidí zo svojho cwd.** Agent spustený v pane dedí cwd
panu. To isté platí pre nástroje, ktoré má k dispozícii — nástroj ukotvený na
koreň projektu nepreskočí do susedného repozitára len preto, že ho vidno v inom pane.
Paralelná práca v dvoch repozitároch preto znamená dvoch agentov v dvoch workspace,
nie jedného agenta s dvoma cestami.

**Fokus je užívateľov.** `--no-focus` pri každom vytvorení, ktoré nemá užívateľa
prepnúť. Výnimkou je len prípad, keď o prepnutie užívateľ explicitne požiadal.

**Socket a stroje sú scope.** ID a mená agentov platia v rámci jedného servera.
Dva uložené SSH stroje môžu mať oba `w1:p1` aj agenta s menom `reviewer`;
`--machine` sa potom musí použiť na objavovanie aj na každý ďalší príkaz.
Výber stroja v TUI príkazy z panu neprepína.

---

## Časť X — Recepty

**Dva repozitáre vedľa seba, každý s agentom.**

```bash
herdr workspace create --cwd /home/evan/dev/06/nativa-php-core  --label core  --no-focus
herdr workspace create --cwd /home/evan/dev/06/nativa-minimal   --label mini  --no-focus
herdr agent list           # mená a stavy oboch agentov
```

**Experiment na vetve bez zásahu do hlavného checkoutu.**

```bash
herdr worktree create --cwd /home/evan/dev/06/nativa-php-core \
  --branch worktree/pokus --base master --no-focus
```

**Čítanie výstupu z iného workspace bez toho, aby som doň čokoľvek poslal.**

```bash
herdr pane list --workspace w6
herdr pane read w6:pC --source recent-unwrapped --lines 120
```

`recent-unwrapped` je správny zdroj na logy a transkripty (spája soft wrap);
`visible` je len to, čo je práve na obrazovke, a `detection` je interný
snapshot, z ktorého Herdr robí rozpoznanie agenta.

**Príkaz na pozadí s čakaním na konkrétny riadok.**

```bash
herdr pane run w6:pD "make test"
herdr pane wait-output w6:pD --match "OK (" --timeout 120000
```

`pane run` potvrdzuje prijatie riadku, nie vykonanie príkazu — preto sa na
výsledok nečaká odhadom, ale `wait-output`.

---

## Časť XI — Čo z toho zostáva

Workspace nie je „väčší pane". Je to hranica, na ktorej sa dá bezpečne povedať
„toto je iný repozitár, s iným git kontextom a iným agentom". Worktree je hranica
druhá — v rámci jedného repozitára — a je to jediná hranica, ktorá oddelí dva git
indexy.

Zvyšok je disciplína: čítať ID z odpovedí namiesto ich hádania, nedeliť pany
tým istým smerom do nekonečna, neodpovedať na `blocked` naslepo a nepliesť si
metadáta workspace s pracovným adresárom panu. Ani jedno z toho nie je
obmedzenie Herdru — je to popis toho, kde presne ležia hranice, ktoré sa dajú
prekročiť len za cenu, ktorá sa nemusí vyplatiť.
