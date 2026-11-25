---
title: "DeepSeek Harness a Cordis ... plugin framework pre budúcnosť"
date: "2026-08-25"
version: "1.0.2"
tags: ["security-series", "deepseek", "cordis", "plugins", "agents", "architecture", "vision"]
excerpt: "Všetko je plugin. Žiadne pevné jadro. Agent mení prostredie za behu. Hot-swap bez reštartu. DeepSeek Harness postavený na Cordis je presne typ modulárneho systému, ktorý potrebujete pre P2P freeNet."
author: "Evan McDan"
author_email: "evan.mcdan@example.test"
category: "Vision"
translation: "deepseek-harness-and-cordis"
published: true
---

DeepSeek Harness nie je ďalší LLM API wrapper. Je to agentný operačný systém, prostredie, v ktorom bežia agenti, a každá schopnosť je samostatný balík.

## Čo je DeepSeek Harness

DeepSeek Harness (dsh) nie je ďalší LLM API wrapper. Je to **agentný framework** ... prostredie v ktorom bežia agenti, a každý agent je plugin.

Jadro je postavené na **Cordis** ... pluginovom frameworku, kde:
- **Nič nie je pevné** ... všetky komponenty sú vymeniteľné
- **Agent mení prostredie** ... môže načítať/odinštalovať pluginy za behu
- **Efekty sa sledujú** ... keď sa plugin odinštaluje, uvoľnia sa zdroje

## DeepSeek vlastní frameworkovú vrstvu

DeepSeek nevendloval len Cordis ... **vendloval celú frameworkovú vrstvu** do vlastného `@deepseek-ai/*` scope:

| Package | Upstream | Čo robí |
|---------|----------|---------|
| `@deepseek-ai/cordis` | cordiverse/cordis 4.0.0-rc.7 | Core plugin framework |
| `@deepseek-ai/cosmokit` | deepseek-harness fork | Utility library |
| `@deepseek-ai/schemastery` | deepseek-harness fork | Schema validation |
| `@deepseek-ai/cordis-plugin-loader` | @cordisjs/plugin-loader | Runtime plugin loading |
| `@deepseek-ai/cordis-plugin-hmr` | @cordisjs/plugin-hmr | Hot module replacement |
| `@deepseek-ai/cordis-plugin-timer` | @cordisjs/plugin-timer | Disposal-aware timers |
| `@deepseek-ai/cordis-plugin-include` | @cordisjs/plugin-include | YAML/JSON config |

Prečo? Lebo publishujú Cordis ako **súčasť Harness**, nie ako závislosť. Každý harness balík deklaruje `cordis` ako peer dependency ... a keď publikujete harness, publikujete aj frameworkovú vrstvu.

### Lokálne patche

DeepSeek nenechal Cordis tak, aký prišiel z upstreamu. Pridali:

- **Fiber lifecycle hardening** ... zatvárajú reentrant disposal gaps, cleanup-time registration je zamietnutá, teardown-notification failures sú izolované per observer
- **Transactional Loader** ... importuje nový entry name PRED disposal, obnoví starý plugin ak zlyhá
- **JSDoc enrichment** ... `@param/@returns` tags pre API reference generator

To nie je len „používame Cordis“ ... **vlastníme frameworkovú vrstvu**.

## Cordis: Žiadne pevné jadro

Tradičné frameworky majú pevné jadro a pluginy sa pridávajú okolo:

```
Tradičné:  [Pevné jadro] + [Plugin 1] + [Plugin 2] + [Plugin 3]
Cordis:    [Plugin 1] ↔ [Plugin 2] ↔ [Plugin 3]
           Žiadne jadro. Všetko je plugin.
```

V Cordise je **aj samotný agent-loop plugin**. Môžete ho vymeniť, upraviť alebo odinštalovať bez toho, aby ste rozbili zvyšok systému.

```yaml
# cordis.yml ... konfigurácia pluginov
plugins:
  - dsh-agent-loop       # Základný agentný cyklus
  - dsh-llm-adapter      # Prispôsobenie pre LLM provider
  - dsh-tool-registry    # Registrácia nástrojov
  - dsh-p2p-sync         # P2P komunikácia
  - dsh-energy-monitor   # Sledovanie zdrojov
  - dsh-outbox           # Async spracovanie
```

Každý riadok je plugin. Žiadny nie je „ten dôležitý“. Všetky sa dajú vymeniť.

## Hot-swap: Zmena za behu

Predstavte si, že váš P2P uzol (chladnička) beží a vy chcete zmeniť spôsob komunikácie. S tradičným prístupom: reštart. S Cordisom:

```php
// Agent zmení plugin za behu
$ctx->uninstall('dsh-http-transport');
$ctx->install('dsh-websocket-transport');

// Žiadny reštart. Žiadny výpadok. Sieť beží ďalej.
```

Plugin sa korektne odinštaluje:
1. Sleduje sa jeho životný cyklus
2. Uvoľnia sa zdroje (DB connections, file handles)
3. Nový plugin prevezme jeho úlohy

## Scoped services: Zdroje s životným cyklom

Každý plugin má svoje **scoped services** ... zdroje, ktoré žijú len počas života pluginu:

```php
// Plugin registrácia scoped service
$ctx->effect(function () use ($pdo) {
    // Toto sa spustí pri inštalácii pluginu
    $repository = new SqliteOutboxRepository($pdo);

    // Vráti service ktorý sa uvoľní pri odinštalovaní
    return $repository;
});

// Keď sa plugin odinštaluje, $repository sa automaticky uvoľní
```

Žiadny memory leak. Žiadne orphan connections. Cordis sa postará.

## Event-driven: Small-talk medzi pluginmi

Pluginy komunikujú cez **typované udalosti** ... presne ako small-talk:

```php
// Plugin A: emituje udalosť
$ctx->emit('p2p/chunk-received', [
    'chunk_id' => $chunk->id(),
    'hash' => $chunk->hash(),
]);

// Plugin B: počúva a reaguje
$ctx->on('p2p/chunk-received', function (array $data) {
    $this->chunkIndexer->index($data);
});

// Plugin C: tiež počúva, ale inak
$ctx->on('p2p/chunk-received', function (array $data) {
    $this->energyMonitor->trackTransfer($data);
});
```

Plugin A nepozná Plugin B ani C. Len „povie“ niečo a kto chce, počúva. Decoupled, testovateľný, rozšíriteľný.

## Ako sa to hodí k P2P

V [predchádzajúcom článku](/blog/chladnicka-ako-p2p-server) sme opísali chladničku ako P2P server. Cordis je **operačný systém** pre takýto uzol:

| Potreba | Cordis riešenie |
|---------|----------------|
| P2P komunikácia | `dsh-p2p-sync` plugin |
| Sledovanie energie | `dsh-energy-monitor` plugin |
| Lokálne úložisko | `dsh-chunk-store` plugin |
| Async spracovanie | `dsh-outbox` plugin |
| Aktualizácie | Hot-swap bez reštartu |
| Audit | Trajectory (append-only log) |

### Trajectory: Auditný záznam

Harness automaticky zaznamenáva všetky udalosti do **append-only logu**:

```php
// Každá akcia je zaznamenaná
$trajectory->record('p2p/chunk-shared', [
    'chunk_id' => $chunk->id(),
    'peers' => ['192.168.1.10', '192.168.1.11'],
    'timestamp' => time(),
]);

// Full audit trail ... kto, čo, kedy, prečo
```

Pre P2P sieť je to kľúčové: viete presne, ktoré chunky sa šírili, kedy a cez aké uzly.

## Cordis a Outbox

Cordis pluginy môžu používať Outbox pattern pre async spracovanie:

```php
// dsh-outbox plugin
$ctx->effect(function () use ($processor) {
    // Registruje handlery pre P2P eventy
    $processor->registerHandler('ChunkShared', function (OutboxMessage $msg) {
        $this->p2pNetwork->broadcast($msg->payload());
    });

    // Background proces spracováva outbox
    $processor->process(100);

    return $processor; // Uvoľní sa pri odinštalovaní
});
```

Outbox zabezpečí, že P2P broadcasty prežijú pád pluginu. Cordis zabezpečí, že plugin sa dá opraviť bez reštartu celého uzlu.

## OpenSpec a Cordis

OpenSpec definuje **čo** sa má stať. Cordis zabezpečí **ako**:

```yaml
# OpenSpec: definícia správania
distribution:
  strategy: gossip
  chunk_size: 4KB
  retry: 3

# Cordis: implementácia ako plugin
plugins:
  - dsh-p2p-gossip       # Gossip protokol
  - dsh-chunk-splitter   # Rozdelenie na 4KB chunky
  - dsh-retry-queue      # Retry mechanizmus
```

OpenSpec môže generovať Cordis konfiguráciu. Cordis môže reportovať späť do OpenSpec. Obojsmerný most.

**Cordis je TypeScript plugin framework ... nie abstraktná myšlienka, ale konkrétny nástroj.**

Framework, ktorý dodržiava tieto zásady:
- Žiadne pevné jadro ... všetko sa dá vymeniť
- Žiadny reštart ... hot-swap za behu
- Žiadny memory leak ... scoped services
- Žiadne tiché zlyhanie ... trajectory logging

Pre P2P freeNet je to presne to, čo potrebujete: uzol, ktorý sa vie aktualizovať, opraviť a rozšíriť bez toho, aby niekto musel fyzicky prísť a reštartovať chladničku.

A keď to spojíte s OpenSpec (orchestrátor), Outbox (async distribúcia) a ACID (konzistencia), máte kompletný stack pre decentralizovanú kognitívnu sieť.

## Zdroje

- [DeepSeek Harness](https://github.com/deepseek-ai/deepseek-harness) ... open-source agentný framework
- [Cordis](https://github.com/deepseek-ai/deepseek-harness/tree/master/vendor/cordis) ... TypeScript plugin framework (súčasť Harness)

→ [Capability seams](/blog/capability-seams): Service Definition / Provider / Consumer
→ [Cordis ... hlboký ponor](/blog/cordis-hlboky-ponor): plugin framework
→ [DSH a P2P](/blog/dsh-a-p2p): distribuovaní agenti v sieti
