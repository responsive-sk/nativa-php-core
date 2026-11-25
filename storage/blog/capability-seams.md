---
title: "Capability seams ... ako DSH oddeľuje schopnosti od implementácií"
date: "2026-08-25"
version: "1.0.2"
tags: ["dsh-series", "deepseek", "architecture", "seams", "providers"]
excerpt: "Každá schopnosť v DSH má tri roly: Service Definition, Service Provider, Consumer. Vzor umožňuje vymeniť filesystem za S3, shell za SSH, alebo sandbox za Landlock ... bez zmeny jediného riadku v consumer."
author: "Evan McDan"
author_email: "evan.mcdan@example.test"
category: "Architecture"
translation: "capability-seams"
published: true
---

Keď agent beží v DSH a potrebuje čítať súbory, nepýta sa „aký filesystem mám?“. Pýta sa „daj mi obsah súboru“. A DSH sa postará o to, aby ten obsah prišiel, z lokálneho disku, zo S3, z E2B sandboxu, alebo z mocku v teste.

Toto je capability seam: **kontrakt, implementácia a spotrebiteľ sú oddelené.**

## Prečo je to problém

Tradične je filesystem priamo napojený na disk. Zmeníš prostredie, musíš meniť kód. Testuješ, potrebuješ reálny disk. Nasadzuješ, dúfaš, že sa nič nezmenilo. Jeden concrete implementation viazaný na jedno prostredie.

## Tri roly každej brány

```
Tradične:  [App] → [Concrete FS] → [Disk]
DSH:       [App] → [FS Seam] → [Local FS]  ← vymeniteľné
                         ↓
                      [S3 FS]     ← alternatívny provider
```

### 1. Service Definition (kontrakt)

Definuje API, čo sa dá robiť, nie ako sa to robí.

```typescript
interface FileSystem {
    read(path: string): Promise<string>
    write(path: string, content: string): Promise<void>
    edit(path: string, patch: string): Promise<void>
    list(dir: string): Promise<string[]>
}
```

### 2. Service Provider (implementácia)

Implementuje kontrakt pre konkrétne prostredie.

```typescript
// Lokálny filesystem
class LocalFileSystem implements FileSystem {
    async read(path: string) { return fs.readFile(path, 'utf-8') }
    async write(path: string, content: string) { await fs.writeFile(path, content) }
}

// Vzdialený E2B filesystem
class E2BFileSystem implements FileSystem {
    async read(path: string) { return this.e2b.readFile(path) }
}
```

### 3. Consumer (používateľ)

Používa API bez toho, aby vedel, ktorý provider beží.

```typescript
class FileSystemTools extends Service {
    async readFile(path: string) {
        // Používa ctx.fs — nevie či je to Local, E2B, alebo S3
        return this.ctx.fs.read(path)
    }
}
```

## Príklad: Filesystem seams

DSH má **6 balíkov** pre filesystem:

| Balík | Rola | Čo robí |
|-------|------|---------|
| `fs/` | Service Definition | Kontrakt: read, write, edit, list |
| `fs-local/` | Provider | Lokálny filesystem |
| `fs-e2b/` | Provider | Vzdialený E2B filesystem |
| `fs-sandbox/` | Provider | Sandbox-enforcing filesystem |
| `fs-observation-policy/` | Policy gate | Read-before-edit |
| `tool-fs/` | Consumer | Model-facing nástroje |

Policy gate (`fs-observation-policy`) je plugin, nie service. Dropne ho a dostanete unconstrained provider namiesto broken tool.

## Výmena bez zmeny consumer

```typescript
// Pred: lokálny filesystem
await root.plugin(LocalFS)

// Po: vzdialený E2B filesystem
await root.plugin(E2BFS)

// Consumer (tool-fs) sa nezmenil ani o riadok
```

Agent nevie, či beží lokálne alebo v cloude. A ani nemusí.

## Ako sa to líši od dependency injection

| Aspekt | DI (napr. Symfony) | Capability Seams |
|--------|-------------------|------------------|
| Závislosť | Constructor injection | Proxy resolution |
| Výmena | Nová trieda v containeri | Nový plugin |
| Životný cyklus | Container manages | Fiber manages |
| Scoping | Request scope | Plugin scope |

DI vám povie „aká trieda sa použije“. Capability seams vám povedia „aká schopnosť je dostupná“, a nechávajú na vás, ako ju implementujete.

→ [DeepSeek Harness ... 50 balíkov](/blog/deepseek-harness-architektura): architektonický prehľad
→ [Cordis ... hlboký ponor](/blog/cordis-hlboky-ponor): plugin framework
→ [Chladnička ako P2P server](/blog/chladnicka-ako-p2p-server): hardvér pre P2P uzly

## Zdroje

- [PHP Manual](https://www.php.net/manual/): oficiálna PHP dokumentácia
- [PHP-FIG, PSR Standards](https://www.php-fig.org/): PHP štandardy
- [Nativa Minimal Documentation](/docs/getting-started): oficiálny návod
- [OWASP Cheat Sheet Series](https://cheatsheetseries.owasp.org/): bezpečnostné best practices
- [W3C, Web Standards](https://www.w3.org/standards/): web štandardy
