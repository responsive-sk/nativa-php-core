---
title: "Ako nám argon2id zrazil shared hosting ... keď 64 MB na jeden login nestačí"
date: "2026-08-24"
version: "1.0.0"
tags: ["security-series", "php", "security", "argon2id", "shared-hosting", "bcrypt", "hashing"]
excerpt: "Jeden riadok kódu, ktorý vypínal celý server. Ako sme zistili, že argon2id na WebSupport shared hostingu spôsobuje OOM kills a čo sme s tým urobili."
author: "Evan McDan"
author_email: "evan.mcdan@example.test"
category: "Security"
translation: "how-sha-hash-algorithm-crashed-shared-hosting"
published: true
---

Bezpečnosť a prevádzkovateľnosť musia ísť spolu. Algoritmus, ktorý spadne server, nie je bezpečný — je nebezpečný.

---

→ [CSP a onclick](/blog/csp-onclick-spravodliva-chyba) — tichá bezpečná diera
→ [Cache wrappers](/blog/cache-wrappers-dobry-slucha-zly-pan) — dobrý sluha, zlý pán
→ [ACID pre deti](/blog/acid-pre-deti) — transakcie a konzistencia
→ [Password hashing](/blog/acid-pre-deti) — prečo algoritmus záleží
→ [Bezpečnosť](/blog/sandbox-a-bezpecnost) — sandbox a obmedzenia

## Zdroje

- [W3C — ACID Properties](https://www.w3.org/TR/xml-core/#sec-conformance) — ACID definícia
- [PHP Manual](https://www.php.net/manual/) — oficiálna PHP dokumentácia
- [SQLite Documentation](https://www.sqlite.org/docs.html) — SQLite referenčná príručka
- [OWASP Cheat Sheet Series](https://cheatsheetseries.owasp.org/) — bezpečnostné best practices
- [Nativa Minimal Documentation](/docs/getting-started) — oficiálny návod
