---
title: "Chladnička ako P2P server ... freeNet zajtra"
date: "2026-08-25"
version: "1.0.2"
tags: ["security-series", "p2p", "freenet", "llm", "decentralized", "vision", "architecture"]
excerpt: "Každá chladnička má ARM procesor, WiFi a stabilné napájanie. Nikto ju nevypína. Čo keby každá chladnička bola P2P uzol s lokálnym LLM a zdieľala chunky vedomostí so susedmi?"
author: "Evan McDan"
author_email: "evan.mcdan@example.test"
category: "Vision"
translation: "the-fridge-as-a-p2p-server"
published: true
---

Pozrite sa na svoju chladničku. Nie, naozaj. Čo tam vidíte? ARM procesor, WiFi, stabilné napájanie, diskový priestor. A hlavne: nikto ju nevypína. Na rozdiel od laptopu alebo servera, chladnička beží nepretržite. Je to najstabilnejší server, aký máte doma.

## Vaša chladnička už má všetko

Pozrite sa na svoju chladničku. Nie, naozaj. Čo tam vidíte?

- **ARM procesor** ... už dávno nie je len spotrebič
- **WiFi modul** ... pripojená k sieti 24/7
- **Stabilné napájanie** ... nikto ju nevypína
- **Diskový priestor** ... na záznamy a dáta
- **Geo-lokalizácia** ... blízko ľuďom, v každej domácnosti

A hlavne: **nikto ju nevypína.** Na rozdiel od laptopu, servera alebo telefónu, chladnička beží nepretržite. Je to najstabilnejší server, aký máte doma.

## Prečo P2P a nie cloud

Cloud je centralizovaný. Jedna firma, jedno datacentrum, jedna politika.

```
Cloud:    [Vy] → [Google/AWS] → [Odpoveď]
P2P:      [Vy] → [Susedia] ↔ [Vzdelanie]
```

V P2P sieti nie ste zákazník ... ste uzol. Každý uzol je:
- **Konzument** ... prijíma chunky vedomostí
- **Poskytovateľ** ... zdieľa chunky so susedmi
- **Derivát** ... spracováva a vylepšuje chunky

## Chunky ako tokeny vedomostí

Predstavte si kúsok vedomostí ako **token** ... malý, prenosný, identifikovateľný:

```php
final readonly class KnowledgeChunk
{
    public function __construct(
        public string $id,        // Unikátny identifikátor
        public string $content,   // Samotné vedomosti
        public string $hash,      // SHA256 pre deduplikáciu
        public int $timestamp,    // Kedy vznikol
    ) {}
}
```

Každý chunk je:
- **Malý** ... dosť veľký na užitočnú informáciu, dosť malý na P2P prenos
- **Identifikovateľný** ... hash zabezpečuje deduplikáciu
- **Verziovateľný** ... timestamp umožňuje sledovať evolúciu
- **Zdieľateľný** ... šíri sa cez sieť ako small-talk

## Ako to funguje

### 1. Lokálny uzol generuje chunk

```php
// Chladnička vygeneruje nový chunk
$chunk = KnowledgeChunk::create('Ako fungujú transakcie v SQLite...');

// Uloží ho lokálne a pridá do Outbox
$uow->transaction(function (PDO $pdo) use ($chunk) {
    $chunk->save($pdo);
    OutboxMessage::create('ChunkShared', [
        'chunk_id' => $chunk->id(),
        'hash' => $chunk->hash(),
    ])->save($pdo);
});
```

### 2. Outbox distribuuje do siete

```php
// Background proces posiela chunk susedom
$processor->registerHandler('ChunkShared', function (OutboxMessage $msg) {
    $this->p2pNetwork->broadcast($msg->payload());
});
```

### 3. Susedia prijímajú a re-broadcastujú

```php
// Susedný uzol dostane chunk
$node->handlePeerChunk($data);

// Overí deduplikáciu
if (isset($this->chunks[$chunk->id])) {
    return; // Už ho mám
}

// Uloží a re-broadcastuje (gossip protokol)
$this->receiveChunk($chunk);
```

### 4. Sieť sa učí

Postupne sa chunky šíria celou sieťou. Každý uzol má lokálnu kópiu všetkých dôležitých vedomostí. Keď jeden uzol vypadne, susedia majú dáta.

## Energetická ekonomika

```
Platíme za hodnotu = čas/online = energia chladničky
```

V P2P sieti nie je mena ... je to **dostupnosť**. Uzol, ktorý je online 24/7, pridáva hodnotu tým, že je dostupný. Chladnička s WiFi a stabilným napájaním je ideálny uzol:

- **Nízka spotreba** ... ARM procesor, nie server
- **Vysoká dostupnosť** ... nikdy nevypnutá
- **Geo-lokalita** ... blízko ľudí
- **Nulové náklady na údržbu** ... funguje sama

## Gossip protokol ... small-talk pre sieť

P2P komunikácia nie je „pošli dáta“ ... je to **small-talk**:

- **Asynchronný** ... nikto nečaká na odpoveď
- **Fault-tolerant** ... ak sused neodpovie, skúsiš iného
- **Evolučný** ... každý uzol pridáva svoje chunky
- **Deduplikačný** ... hash zabezpečí, že sa neposielajú duplicitné dáta

Presne ako small-talk medzi ľuďmi: „Hej, toto som sa naučil. Keď budeš mať čas, pozri sa na to.“

## Čo bráni realite?

### 1. LLM sú ešte veľké

Ale zmenšujú sa:
- Mistral 7B ... beží na Raspberry Pi
- Phi-3 ... 3.8B parametrov, edge-ready
- Qwen 2 ... kvantizované verzie pre mobil

### 2. P2P protokoly sú komplexné

Ale existujú:
- **libp2p** ... modulárny P2P framework
- **IPFS** ... content-addressed storage
- **Gossipsub** ... publish-subscribe pre messaging

### 3. Hardvér je drahý

Ale lacnie:
- Raspberry Pi 5 ... $80
- ESP32 ... $5
- Smart appliances už majú ARM + WiFi

### 4. Nikto to nepotrebuje

Zatiaľ nie. Ale keď centrálni poskytovatelia LLM zvýšia ceny alebo obmedzia prístup, P2P alternatíva bude jediná cesta.

## OpenSpec ako orchestrátor

OpenSpec nie je len pre kód ... môže riadiť P2P sieť:

```yaml
# OpenSpec definuje pravidlá pre P2P distribúciu
distribution:
  strategy: gossip    # Gossip protokol
  chunk_size: 4KB     # Veľkosť chunku
  dedup: sha256       # Hash pre deduplikáciu
  retry: 3            # Počet pokusov
  ttl: 86400          # Životnosť chunku (24h)
```

## Evolučná fáza

```
1. Cache wrappers
   → Ukladáte odpovede (čítanie)

2. Outbox pattern
   → Ukladáte udalosti (zápis)

3. P2P cache
   → Zdieľate odpovede aj udalosti (distribúcia)

4. Chladnička ako server
   → Každý uzol je aj producent, aj spotrebiteľ
   → FreeNet nie je vízia ... je to logický dôsledok
```

**Cache vás naučila, že odpoveď nemusí byť vždy nová, len blízka.**

Chladnička ako P2P server je logickým dôsledkom toho, čo ste sa naučili v predchádzajúcich článkoch:

- ACID vás naučil, že dáta musia byť atomické
- Outbox vás naučil, že odoslanie nemusí byť okamžité, len isté
- Cache vás naučil, že odpoveď nemusí byť nová, len blízka

A teraz: **vedomosti nemusia byť centrálne, len distribuované.**

Chladnička nie je sci-fi. Je to server, ktorý už máte doma. Stačí ho zapnúť do siete.

## Zdroje

- [Mistral 7B](https://github.com/mistralai/mistral-src) ... open-source LLM, beží na Raspberry Pi
- [Phi-3](https://github.com/microsoft/Phi-3CookBook) ... 3.8B parametrov, edge-ready
- [libp2p](https://github.com/libp2p/go-libp2p) ... modulárny P2P framework
- [IPFS / Kubo](https://github.com/ipfs/kubo) ... content-addressed storage
- [Raspberry Pi](https://www.raspberrypi.com/) ... $80 hardvér pre P2P uzly

→ [DSH a P2P](/blog/dsh-a-p2p): distribuovaní agenti v sieti
→ [Capability seams](/blog/capability-seams): kontrakt, implementácia, spotrebiteľ
→ [Filozofia menej vrstiev](/blog/filozofia-menej-vrstiev): prečo je PHP rýchle
