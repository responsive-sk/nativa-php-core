---
title: "Hyprland namiesto XFCE: 110 skratiek, 367 riadkov Lua a to, čo sa preniesť nedá"
date: "2026-10-28"
tags: ["hyprland", "linux", "wayland", "xfce", "cachyos", "lua", "prostredie"]
excerpt: "Hyprland 0.56.2 na CachyOS, nainštalovaný vedľa XFCE, nie namiesto neho. Zmerané: 110 aktívnych skratiek, z toho 22 s pôvodným XFCE náprotivkom, 40 pod klávesou Super a 367 riadkov Lua configu. Článok je o tom, čo zo svalovej pamäte prežilo, čo musí doplniť iný program a kde compositor končí."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Linux"
published: true
---

Na tomto stroji bol doteraz ako okenný manažér XFCE.
Dnes je to Hyprland 0.56.2, ale XFCE sa neodstránilo
v prihlasovacej obrazovke sú stále obe session a celý prechod sa dá vrátiť
jedným kliknutím.
To nie je zdržanlivosť, je to podmienka
za ktorej sa dá nové prostredie skúšať bez toho
aby zlyhanie znamenalo preinštalovanie.

Toto je zápis merania, nie návod na obdivovanie animácií.
Zaujímalo ma jediné
**koľko z návykov z XFCE sa dá preniesť a čo zvyšok stojí.**
Čísla nižšie sú prečítané z bežiacej session 28. októbra 2026
nie z dokumentácie.

---

## Časť I > Dva svety v jednom prihlasovaní

Hyprland nie je nadstavba XFCE.
Je to Wayland compositor, teda v jednej procesovej vrstve okenný manažér
session a skladanie obrazu.
XFCE má tieto veci rozdelené medzi `xfwm4`, `xfce4-session` a `xfdesktop`
tu je to jedna binárka
ktorá sa stará o všetko od rozloženia okien po tapetu.

Na disku to vyzerá takto:

```
/usr/share/xsessions/xfce.desktop
/usr/share/wayland-sessions/hyprland.desktop
/usr/share/wayland-sessions/hyprland-uwsm.desktop
/usr/share/wayland-sessions/xfce-wayland.desktop
```

Prihlasovanie beží cez `lightdm`.
Stará session teda nie je spomienka, je to položka v greeteri
a zároveň dôvod, prečo mohli zostať nástroje, na ktoré som zvyknutý
`xfce4-terminal`, `thunar`, `xfce4-taskmanager`, `exo-open`.
Všetky fungujú aj pod Waylandom (cez XWayland, ak nemajú natívny režim)
takže prechod nezačína hľadaním nového terminálu.

Monitor sa pripojí sám:
`HDMI-A-1`, `1680x1050@59.883`, `scale: 1`
rozlíšenie z `preferred` režimu, poloha `auto`.
V `hyprctl monitors` je ešte jeden údaj, ktorý stojí za pozornosť
`reserved: 0 30 0 0`.
Tých 30 pixelov zhora si rezervoval panel, teda waybar.
Compositor o paneli nevie; panel si miesto vyhradí cez layer-shell.
Toto je prvý rozdiel, ktorý sa nedá prehliadnuť
**v Hyprlande je panel obyčajný program, ktorý sa musí spustiť.**

---

## Časť II > Config je program, nie zoznam riadkov

V XFCE sa nastavenia klikali v `xfce4-settings-manager` a skončili v XML.
Config je súbor, ktorý sa pri štarte **vykoná**:

```
~/.config/hypr/hyprland.lua      367 riadkov
/usr/share/hypr/hyprland.lua     predloha, ktorú dodáva balík
```

Hyprland 0.56 na tejto inštalácii číta config v Lua
balík dodáva predlohu `/usr/share/hypr/hyprland.lua`
a `--verify-config` potvrdí voľbu riadkom `Using lua config found at ...`.
To nie je obal nad textovým formátom
je to skript, ktorý volá API `hl`:

| čo chceš | ako sa to zapíše |
|---|---|
| nastavenie vlastnosti | `hl.config({ general = { gaps_in = 4 } })` |
| premenná prostredia | `hl.env("GTK_THEME", "Adwaita-dark")` |
| monitor | `hl.monitor({ output = "", mode = "preferred", position = "auto" })` |
| akcia pri štarte | `hl.on("hyprland.start", function() ... end)` |
| klávesová skratka | `hl.bind(keys, akcia, { description = "..." })` |
| pravidlo pre okno | `hl.window_rule({ match = { class = "..." }, float = true })` |
| gesto | `hl.gesture({ fingers = 3, direction = "horizontal", action = "workspace" })` |

Dôsledok je praktický:
cyklus
ktorý generuje desať skratiek pre pracovné plochy
nie je desať skopírovaných riadkov, ale `for i = 1, 10 do`.
Tabuľka skratiek v tomto configu takto vznikla pre plochy
pre smerové klávesy aj pre numpad.

Overenie configu je prvý príkaz, ktorý má zmysel poznať:

```bash
hyprland --verify-config
# ... [cfg] Using lua config found at /home/evan/.config/hypr/hyprland.lua
# ======== Config parsing result:
# config ok
```

Je to použiteľná brána, nie len výpis.
Dôkaz je merateľný.
Na kópii configu s nedokončeným `hl.bind(`
na riadku 370 skončil `hyprland --verify-config` s kódom 1
a hláškou `unexpected symbol near <eof>`.
Čistý config skončil s kódom 0 a `config ok`.
Chyba sa teda hlási s číslom riadku a dá sa použiť v skripte.
Len ju nikto nespúšťa za teba.
Za uložením configu nestojí git hook ani CI.
`hyprctl configerrors` je náprotivok pre bežiacu session.
V tejto session vracia prázdny výstup.

Reload je preto dôležitejší než v XFCE.
Zmeral som ho priamo:
do configu pribudla dočasná skratka `SUPER + F20`
`hyprctl reload`, v `hyprctl binds` bolo **111** položiek
po vrátení súboru späť na 18 271 bajtov a ďalšom reloade **110**.
Zoznam sa nezmnožuje, hoci sa celý súbor vyhodnotí odznova
inak by po dvoch reloadoch mala každá skratka dvojča a po desiatich desať.

Na jednorazové veci existuje `hyprctl eval '<lua>'`
ktorý vykoná Lua reťazec proti bežiacemu compositoru.
Odpoveď je `ok`, nie návratová hodnota
je to nástroj na zmenu, nie na čítanie.

---

## Časť III > 110 skratiek, 22 s menom XFCE

`hyprctl binds` je jediný zdroj pravdy o tom, čo je naozaj aktívne.
V tejto session vypísal **110** položiek.
Z toho **40** používa modifikátor Super (`modmask: 64`)
teda klávesu Windows, a **22** má v popise pôvodnú XFCE skratku.
To posledné číslo je zmysel celého cvičenia
presne pätina skratiek má v sebe zapísané, odkiaľ prišla.

| akcia | Hyprland | XFCE náprotivok |
|---|---|---|
| terminál | `Super + Return` | `Ctrl + Alt + T` |
| správca súborov | `Super + E` | `Ctrl + Alt + F` |
| štart menu | `Super + R` | `Alt + F2` |
| zavrieť okno | `Super + Q` | `Alt + F4` |
| celá obrazovka | `Super + F` | `Alt + F11` |
| maximalizovať | `Alt + F10` | `Alt + F10` |
| pracovná plocha 1–10 | `Super + 1…0` | `Ctrl + F1…F10` |
| plocha vľavo/vpravo | `Ctrl + Alt + ←/→` | to isté |
| zámok obrazovky | `Super + X` | `Ctrl + Alt + L` |
| menu vypnutia | `Super + M` | `Ctrl + Alt + Delete` |
| screenshot | `Print` | `Print` |

Popis pri skratke nie je dekorácia.
`hl.bind` ho berie z tabuľky volieb:

```lua
local function bind(keys, action, desc, flags)
  local opts = flags or {}
  opts.description = desc
  return hl.bind(keys, action, opts)
end

bind("SUPER + Return", hl.dsp.exec_cmd("xfce4-terminal"), "Terminál")
```

Keď sa popis pošle ako tretí argument namiesto tabuľky
skratka sa zaregistruje, ale popis sa stratí
v mojom teste to bolo vidieť ako 111 skratiek bez jedinej zhody
na hľadaný text.
Rozdiel medzi „skratka existuje"
a „skratka je vysvetlená" je teda jeden typ argumentu.

Druhý údaj, ktorý `hyprctl binds` prezradí
pri týchto položkách je `dispatcher: __lua`.
Hyprland rozlišuje vstavané akcie od tých, ktoré sú zadefinované v configu
ak by sa skratky zapisovali starším textovým spôsobom
dispatcher by mal iné meno.
Nie je to detail pre estétov
znamená to
že každá skratka prejde
cez Lua vrstvu a jej správanie sa dá zmeniť bez rekompilácie.

---

## Časť IV > Ako si pridať vlastnú skratku

Postup má štyri kroky a ani jeden z nich nie je „reštartuj session".

**Prvý krok:**
zisti, či je kombinácia voľná.

```bash
hyprctl binds | grep -B3 -A5 -E 'key: A$'
```

Ak príkaz nič nevráti, `Super + A` nie je obsadené.
Keď vráti, prečítaj `modmask`:
`64` je Super, `8` Alt, `4` Ctrl
`1` Shift.
`key: A` s `modmask: 64` je teda `Super + A`.
Holé `A` má `modmask: 0`.

**Druhý krok:**
pridaj riadok do configu, pod existujúce skratky.

Pomocná funkcia `bind` je `local` na riadku 152 toho istého súboru
takže platí až do konca
nové skratky patria za ňu, nie pred ňu.
Príklad, ktorý som pridal a zase odstránil:

```lua
bind("SUPER + A", hl.dsp.exec_cmd("pavucontrol"), "Zvuk - pavucontrol")
```

Prvý argument je kombinácia, druhý akcia, tretí popis.
Ak by si volal `hl.bind` priamo a nie cez túto pomocnú funkciu
popis musí byť v tabuľke:
`hl.bind(keys, action, { description = "..." })`.

Akcie, ktoré pokryjú väčšinu potrieb:

| chceš | zapíš |
|---|---|
| spustiť program | `hl.dsp.exec_cmd("pavucontrol")` |
| zavrieť okno | `hl.dsp.window.close()` |
| fokus smerom | `hl.dsp.focus({ direction = "left" })` |
| prejsť na plochu | `hl.dsp.focus({ workspace = 3 })` |
| celá obrazovka | `hl.dsp.window.fullscreen()` |

**Tretí krok:**
over to bez toho, aby si ohrozil session.

```bash
hyprland --verify-config                     # config ok, exit 0
hyprctl reload
hyprctl binds | grep -c '^bind'              # 110 -> 111
hyprctl binds | grep -A6 'Zvuk - pavucontrol'
```

Posledný príkaz musí vrátiť blok s `description: Zvuk - pavucontrol`
a `dispatcher: __lua`.
Ak počet skratiek stúpol a popis sa nenašiel
popis skončil mimo tabuľky volieb
presne ten prípad z časti III.

**Štvrtý krok:**
stlač kombináciu.
Ak sa nič nestane, `hyprctl configerrors` povie, či config prešiel.
Ak prešiel a skratka je v `hyprctl binds`
problém je skôr v akcii než v klávese
napríklad program neexistuje, čo sa overí cez `command -v pavucontrol`.

Odstránenie je ten istý postup opačne:
zmaž riadok, `hyprctl reload`
počet skratiek sa vráti na 110.
Celý príklad som takto odmeral:
110 pred pridaním, 111 po ňom
110 po vymazaní a súbor bajtovo rovnaký s pôvodným.

Dve veci sa pri tom ľahko pokazia.
Názov klávesy
špeciálne klávesy majú mená typu `XF86AudioRaiseVolume`, myš `mouse:272`.
A popis na nesprávnej pozícii:
`hl.bind` očakáva tabuľku volieb
takže reťazec na jej mieste sa jednoducho ignoruje
a skratka ostane bez popisu.
Mediálne klávesy navyše potrebujú `{ locked = true, repeating = true }`
inak pri zamknutej obrazovke nereagujú a pri podržaní sa nezopakujú.

---

## Časť V > Tiling: čo zmizne a čo je za to

Dlaždicový správca okien nerobí nič, čo by XFCE nevedelo; robí to inak.
Základné pravidlo:
**okno nemá voľnú polohu, má suseda.**
Z toho vyplýva zoznam vecí, ktoré prestanú dávať zmysel.

| zvyk z XFCE | čo je tu namiesto neho |
|---|---|
| minimalizovať okno | nič : okno sa buď vidí, alebo patrí na inú plochu |
| `Alt + Tab` cez zoznam | `Alt + Tab` cyklí okná a posúva ich na vrch (`cycle_next` + `alter_zorder`) |
| maximalizovať | `Alt + F10` (maximized), `Super + F` (celá obrazovka) |
| presun okna myšou za titulok | `Super + ľavé tlačidlo`, alebo `Super + Shift + šípky` |
| zmena veľkosti za roh | `Super + pravé tlačidlo`, alebo `Super + Alt + šípky` |

Pracovných plôch je desať a plochy nie sú „obrazovky"
ale menovité zoznamy okien.
Plocha 10 je pod klávesou `0`, čo je detail
ktorý pri desiatich skratkách vznikne sám.
Špeciálna plocha `magic` je scratchpad:
`Super + S` ju zobrazí
`Super + Shift + S` do nej pošle okno.
Rovnaká myšlienka ako „rolovačka" v paneli XFCE, len bez panelu.

Dve veci, ktoré tu majú zmysel hneď:

- **skupiny** (`Super + G`, `Super + Ctrl + Tab`) : okná sa zbalia do jednej
  dlaždice s tabuľkami; náhrada za prepínanie medzi troma terminálmi.
- **pseudotiling** (`Super + P`) : okno si drží šírku, akú malo, a nové okná
  sa delia len o zvyšok.

Nepríjemná stránka je rovnaká ako všade v tiling správcoch:
aplikácia
ktorá si vypýta konkrétnu veľkosť okna, ju nedostane.
Preto má config pravidlo `suppress-maximize-events`
pre všetky triedy okien
požiadavka na maximalizáciu sa jednoducho zahodí
lebo v mriežke nemá význam.
A preto sú dialógy (`pavucontrol`, `blueman-manager`
`nm-connection-editor`
kalkulačka) menovite uvedené ako plávajúce a vycentrované
to je jediná skupina okien, kde má zmysel, aby si určovali veľkosť samé.

---

## Časť VI > Čo compositor nerobí

Toto je najčastejšie miesto, kde človek po inštalácii zostane stáť.
Hyprland nekreslí panel a nespravuje tapetu.
Nezamyká obrazovku a nerobí screenshoty.
Každá z tých vecí je samostatný program, ktorý sa musí nainštalovať a spustiť.
Tabuľka z tohto stroja:

| úloha | program | stav |
|---|---|---|
| panel | `waybar` | beží, rezervuje 30 px zhora |
| tapeta | `hyprpaper` | beží, `hyprpaper.conf` |
| zámok a vypnutie displeja | `hyprlock` · `hypridle` | beží `hypridle` |
| menu vypnutia | `hyprshutdown` | nainštalovaný |
| štart menu | `hyprlauncher` | nainštalovaný |
| screenshot | `grim` · `slurp` · `jq` · `wl-copy` | všetko prítomné |
| jas displeja | `brightnessctl` | **chýba** |
| polkit dialóg | `polkit-gnome-authentication-agent-1` | beží |
| tray: sieť, Bluetooth | `nm-applet` · `blueman-applet` | oba bežia |

Screenshot je najlepší príklad toho, ako to funguje.
`Print` nie je klávesa, ktorú Hyprland pozná
je to skratka na shellový reťazec:

```bash
mkdir -p ~/Pictures/Screenshots
f=~/Pictures/Screenshots/$(date +%Y%m%d-%H%M%S).png
grim "$f" && wl-copy < "$f" && hyprctl notify 1 1500 0 'Screenshot uložený'
```

Pri výbere pribudne `slurp`, pri okne `hyprctl activewindow -j` a `jq`
ktoré z JSON-u vytiahnu `at` a `size`.
Ak `grim` chýba
celý reťazec zlyhá na prvom riadku a človek to zistí podľa toho
že sa nič nestalo.
Config preto začína kontrolou a notifikáciou namiesto tichého zlyhania:

```bash
command -v grim >/dev/null || {
  hyprctl notify 2 5000 0 'Chýba grim/slurp - sudo pacman -S grim slurp'; exit 0; }
```

Rovnaký vzor je pri jase:
skratky `XF86MonBrightnessUp/Down` existujú
ale `brightnessctl` na tomto stroji nie je nainštalovaný
takže klávesy nerobia nič.
Na desktope je to správne správanie
na notebooku by to bola diera, ktorú by človek našiel až v okamihu
keď potrebuje stlmiť obrazovku.

Notifikácie sú jediná výnimka.
`org.freedesktop.Notifications` je v session prítomné ako služba
ktorú si Hyprland aktivuje na požiadanie
takže `dunst` netreba a `hyprctl notify` funguje bez ďalšieho démona.

---

## Časť VII > Tri pravidlá, ktoré vyzerajú ako hack

Každý config pre compositor obsahuje aspoň tri riadky
ktoré nedávajú zmysel, kým človek nepozná dôvod.
Toto sú moje:

**`suppress_event = "maximize"` pre všetky okná.**
Tiling okno nemá maximalizovaný stav; aplikácie
ktoré ho nastavujú (prehliadače, Electron), by inak posielali požiadavky
na ktoré compositor nemá čo odpovedať.

**`fix-xwayland-drags` s prázdnym `class` a `title`.**
Pravidlo matchuje `class = "^$"`, `title = "^$"`, `xwayland = true`
`float = true`.
Sú to anonymné X11 okná, ktoré XWayland vytvára počas ťahania
bez pravidla `no_focus` kradnú fokus pri každom `drag`
a okno pod kurzorom prestane reagovať.

**`move-hyprland-run`.**
Utilita, ktorú compositor použije pri `hyprland-run`, je plávajúce okno
pravidlo ju presunie na `20 monitor_h-120`, teda doľava dole
aby nezakryla stred obrazovky.

Ani jedno z nich nie je obchádzka.
Sú to miesta, kde sa starý svet X11 a nový Wayland stretávajú
a compositor nemá dosť informácií na to, aby rozhodol sám.

---

## Časť VIII > Ako si to overiť bez toho, aby som veril configu

Pri väčších úpravách používam to isté poradie ako v časti IV
len s dvoma príkazmi navyše:

```bash
hyprland --verify-config            # prejde config bez štartu session
hyprctl configerrors                # chyby v bežiacej session
hyprctl binds | grep -c '^bind'     # koľko skratiek je naozaj aktívnych
hyprctl reload && hyprctl binds     # či reload nič nezdvojil
hyprctl monitors | head -12         # rozlíšenie, scale, rezervované miesto
hyprctl clients                     # čo je otvorené a na ktorej ploche
```

`hyprctl` je zároveň dokumentácia
`hyprctl getoption general:gaps_in` vráti hodnotu aj príznak `set:`
`4` a `set: true` znamenajú, že ju config naozaj nastavil
kým `decoration:dim_inactive` vracia `false` a `set: false`, teda default
ktorý nikto neprepísal.
Posledné dva príkazy z toho zoznamu sú dôvod
prečo sa tento článok dal napísať bez dohadov
všetko, čo tvrdím o počtoch, je výstup z bežiaceho compositoru.

---

## Časť IX > Čo zostáva

Preniesť sa dá viac, než som čakal
22 skratiek má pri sebe pôvodné XFCE meno a zvyšok je malý slovník
fokus, presun, plocha, okno.
Nedá sa preniesť zvyk, že okno má svoju polohu.
Ten treba vymeniť, nie obísť
každý pokus obísť ho (ručné presúvanie myšou) skončí tým
že sa mriežka rozpadne a človek si ju musí nechať prepočítať.

Cena je inde, než som čakal.
Nie v učení skratiek
v tom, že prostredie je teraz program, ktorý udržiavam.
Panel, tapeta, zámok
screenshot aj jas sú osobitné programy
s vlastnými konfiguráciami a vlastnými spôsobmi zlyhania.
XFCE malo jedného správcu nastavení, ktorý vedel o všetkom.
Tu sú štyri súbory v `~/.config/hypr`
`hyprland.lua`, `hyprpaper.conf`, `hypridle.conf` a `hyprlock.conf`.
K tomu waybar s vlastným `config.jsonc` a `style.css`.
A jeden `busctl` príkaz, ktorým sa dá zistiť, čo z toho naozaj beží.

Zostáva jedna vec, ktorú by som nemenil:
XFCE session je stále v greeteri.
Dobré prostredie sa pozná podľa toho, že sa z neho dá odísť
a že ten odchod nie je preinštalovanie.
