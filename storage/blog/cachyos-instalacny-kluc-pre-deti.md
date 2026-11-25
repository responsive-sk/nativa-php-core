---
title: "Arch balíky pre deti ... diel 0: kľúč na inštaláciu"
series: "Arch balíky pre deti"
series-part: "0"
date: "2026-09-25"
version: "1.0.2"
published: true
tags: ["arch", "linux", "cachyos", "installation", "security", "beginners"]
excerpt: "Nultá lekcia série: overenie ISO, zápis inštalačného kľúča a inštalácia CachyOS. Krok, ktorý rozhoduje o celom zvyšku — a prvé miesto, kde motto série funguje ešte pred systémom."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Security"
translation: "cachyos-install-key"
---

> Toto je diel 0 série „Arch balíky pre deti“ — krok pred každým príkazom:
> overenie ISO, inštalačný kľúč a inštalácia CachyOS. Mapa série:
> [pacman](/blog/pacman-pre-deti), [rebrík dôvery](/blog/repozitare-a-dovera-pre-deti),
> [Flatpak ako výnimka](/blog/flatpak-pre-deti), [motto](/blog/kluc-trust-nothing-pre-deti),
> [overovanie AI tvrdení](/blog/ai-bezpecnostne-tvrdenia-pre-deti), sudo LAST
> a [shell](/blog/shell-pre-deti). Dnes — všetko, čo musí platiť, kým padne
> prvý balík.

Si pred starým noťasom a rozhodnutím. Na webe CachyOS čaká **ISO obraz**
— celý systém v jednom súbore — a vedľa neho dlhý reťazec znakov,
ktorý vyzerá ako šum.

Presne v tejto lekcii sa šum stáva nástrojom: overíš ním ISO, zapíšeš
inštalačný kľúč a pustíš inštaláciu. Krok za krokom — a so zásadami
série, ktoré tu fungujú ešte skôr, než má systém akýkoľvek príkaz.

## Čo je inštalačný kľúč

**Inštalačný kľúč** je USB kľúč, z ktorého sa počítač nabootuje do inštalátora. Nie je to kópia ISO súboru na kľúč — obraz sa zapisuje tak, aby stroj vedel z neho naštartovať celý inštalačný systém.

CachyOS sa inštaluje **online** — inštalátor si počas behu sťahuje balíky z repozitárov. Preto wiki požaduje stabilné pripojenie k internetu a ako minimum 3 GB RAM s 30 GB miesta na disku. Odporúčaná čiara je ale vyššie:
8 GB RAM, 50 GB na SSD a procesor x86-64-v3.

Na kľúč potrebujujete aspoň 8 GB voľného miesta. A jednu úprimnosť hneď na začiatku:
obsah kľúča zápis prepíše — nič iné na ňom nezostane.

## Overenie: integrita a pôvod

Kým čokoľvek na čokoľvek zapíšeš, over stiahnutý ISO obraz — dvoma testmi, každý na iný atribút:

| Test | Čo overuje | Úspech vyzerá ako |
|---|---|---|
| SHA256 | integrita — súbor sa pri sťahovaní nepokodil | `OK` |
| GPG podpis | pôvod — ISO podpísal tím CachyOS | `Good signature` |

Integrita: v priečinku so stiahnutým ISO obrazom spusti kontrolný súčet.

```bash
sha256sum -c cachyos-desktop-linux-260809.iso.sha256
# cachyos-desktop-linux-260809.iso: OK
```

Číslo v mene súboru je verzia vydania — tvoja bude iná. Názov `.sha256`
súboru ber z rovnakého miesta ako ISO: oficiálny web, SourceForge alebo mirror. Wiki to hovorí bez obštrukcie — krok overenia rob vždy, aby inštalácia nemala problém už pri výrobe bootovateľného média.

Pôvod: ISO obraz bez podpisu je len súbor, ktorý tvrdí o sebe, že je CachyOS.
Podpis je ten, kto za súbor berie zodpovednosť.

```bash
gpg --keyserver hkps://keys.openpgp.org --recv-key F3B607488DB35A47
gpg --verify cachyos-desktop-linux-260809.iso.sig cachyos-desktop-linux-260809.iso
# gpg: Good signature from "CachyOS <..."
```

Zlý podpis alebo cudzí kľúč znamená ISO nepoužiť — wiki to označuje ako Danger a nie je to vystrašenie, je to postup. Kryptografia hovorí suchým jazykom:
`OK`, `Good signature`.
Presne o tom je [diel 5](/blog/ai-bezpecnostne-tvrdenia-pre-deti) — overuj čísla, nie tón.

## Zápis na kľúč — sudo LAST

Motto série znie TRUST NOTHING. READ THE DOCS. sudo LAST — a funguje
ešte skôr, než existuje systém, na ktorom by si si ho mohol prilepiť
na monitor. Čítanie (sťahovanie, overenie) beží bez práv správcu.
Zápis na kľúč ich potrebuje. Poradie je rovnaké ako pri každom balíku:
najprv čítanie, potom zápis.

Najjednoduchšia cesta je balenaEtcher: vyber ISO súbor, vyber kľúč,
Flash. Ak sa pýta heslo správcu, pýta si ho na zápis — nie na sťahovanie.

Kto chce vidieť, čo sa deje, napíše to rovno do terminálu:

```bash
# ktorý disk je kľúč? pohľadaj ho podľa mena a veľkosti
sudo fdisk -l

# zápis obrazu — <usbdrive> nahraď označením kľúča, napr. sdb
sudo dd bs=4M if=cachyos-desktop-linux-260809.iso of=/dev/<usbdrive> status=progress oflag=sync
```

A tu je moment, ktorý stojí za pomalé čítanie: `dd` zapisuje tam, kam
povieš, bez otázok a bez koša. Zlý disk za `of=` znamená stratu dát.
Preto najprv overenie, potom `fdisk -l` — a kľúč si over dvakrát, podľa
mena aj podľa veľkosti.

Wiki popisuje aj Rufus (Windows) a Ventoy (Linux aj Windows — kľúč,
ktorý unesie viac ISO naraz). Každý nástroj robí to isté: dá stroju ISO
tak, aby z neho vedel nabootovať.

## Inštalácia, krok za krokom

Nabootuj z kľúča a klikni na Launch Installer. Skôr než začneš, tri veci
z wiki, ktoré sa platia neskôr drahšie.

Po prvé, **UEFI režim**: Secure Boot a CSM musia byť v nastaveniach
BIOS/UEFI vypnuté. Nepresvedčený? `efibootmgr -v` v termináli ISO vypíše
EFI premenné — ak odpovie, že ich systém nepodporuje, stroj nabootoval
v Legacy/BIOS režime.

Po druhé, **ručné delenie disku**: wiki ho preferuje — možnosti
„Install alongside“ a „Replace“ nie sú stopercentné a môžu inštaláciu
zrútiť. Pre boot manager Limine potrebuješ dva oddiely:

| Oddiel | Súborový systém | Veľkosť | Mount | Flag |
|---|---|---|---|---|
| boot | FAT32 | ≥ 4096 MiB | `/boot` | boot |
| root | podľa voľby (pozri wiki) | ≥ 20000 MiB | `/` | — |

Potom inštalátor vedie sám: jazyk a časové pásmo, klávesnica, prostredie
desktopu (vyberá sa raz — viac naraz neinštaluje), balíky, prihlasovacie
údaje. Súhrn si prečítaj poriadne — Install Now je prvý klik, po ktorom
už spätná cesta vedie cez preinštalovanie.

## Úprimne — kedy kľúč nestačí

Tri situácie, kedy kľúč nedá rady.

Po prvé, overenie chráni pred chybným súborom — nie pred tvojou rukou.
`dd` zapisuje bez otázok a inštalátor s ručnými oddielmi vie zobrať celý
disk. Rebrík dôvery z [dielu 2](/blog/repozitare-a-dovera-pre-deti) tu
začína o stupeň nižšie: pri inštalácii si Ty stupeň 4 — a záloha je
jediná spätná cesta, ktorá funguje aj vtedy, keď už nič iné.

Po druhé, online inštalátor bez stabilného internetu neinštaluje.
Wi-Fi s prihlasovacou stránkou nie je stabilné pripojenie — inštalácia
sa môže skončiť v polovici. A po zlyhanom behu wiki odporúča nabootovať
z ISO znova: neúspešná inštalácia odmontuje oddiely nekorektne a
ďalšie pokusy padajú do chýb.

Po tretie, starý stroj bez x86-64-v3. CachyOS optimalizuje balíky pre
nové mikroarchitektúry a odporúčaná čiara je x86-64-v3 — procesory od
Zen a Haswella nahor. Minimum je nižšie, ale keď stroj nepatrí ani
k nemu, čestne: existujú distribúcie s menej náročnou starostlivosťou.
Motto „pre deti“ zjednodušuje cestu, nie pravdu — a pravda je, že nie
každý noťas je CachyOS stroj.

## Čo bude ďalej

Diel 1 — [pacman](/blog/pacman-pre-deti). Systém je nainštalovaný a čaká
na prvý príkaz: ako nájsť, prečítať a nainštalovať balík — a prečo si
nemusíš pamätať názvy.

## Zdroje

- [CachyOS Wiki — Stiahnutie a overenie](https://wiki.cachyos.org/sk/cachyos_basic/download/) —
  SHA256, GPG podpis, oficiálne zdroje ISO
- [CachyOS Wiki — Príprava inštalácie](https://wiki.cachyos.org/sk/installation/installation_prepare/) —
  požiadavky, výroba bootovateľného kľúča (Etcher, dd, Rufus, Ventoy)
- [CachyOS Wiki — Inštalácia Desktop/Laptop](https://wiki.cachyos.org/sk/installation/installation_on_root/) —
  UEFI, ručné delenie disku, priebeh inštalácie
