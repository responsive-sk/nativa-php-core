---
title: "How argon2id crashed our shared hosting ... when 64 MB per login is too much"
date: "2026-08-24"
version: "1.0.0"
tags: ["security-series", "php", "security", "argon2id", "shared-hosting", "bcrypt", "hashing"]
excerpt: "One line of code that took down the entire server. How we discovered argon2id causes OOM kills on shared hosting and what we did about it."
author: "Evan McDan"
author_email: "evan.mcdan@example.test"
category: "Security"
translation: "ako-sha-hash-algorytm-zrazil-shared-hosting"
published: true
---

One line of code. `PASSWORD_ARGON2ID`. The most recommended hashing algorithm in PHP. It took down our entire server on the first login attempt.

## What happened

We had a finished, tested, secure system. Our PasswordHasher used `PASSWORD_ARGON2ID` ... the recommended PHP hashing algorithm with excellent security properties. Everything worked on development, in Docker, on VPS.

Then came shared hosting.

First login attempt: server crashed. Second one too. Third ... same result. The FPM worker got an OOM kill and vanished without a trace.

## Why argon2id crashes servers

`PASSWORD_ARGON2ID` is designed to be **slow and memory-hungry** ... and that's exactly what makes it secure. An attacker can't brute-force passwords because every verification attempt costs:

- **64 MB RAM** (memory_cost = 65536 KB)
- **4 iterations** (time_cost)
- **1 thread**

On a VPS with 2 GB RAM, that's fine. On shared hosting where you get 128–256 MB for the entire PHP process, it's **instant death**.

```php
// PasswordHasher.php ... before the change
public const string ALGORITHM = PASSWORD_ARGON2ID;
```

One line. No logic error, no bug. Just an algorithm that demands more memory than the server has.

## How we diagnosed it

Step one: we looked at the logs. Nothing ... the FPM worker simply vanished, no fatal error, no stack trace. It just quietly died.

We added early logging right at the top of `public/index.php`:

```php
error_log('[REQ] ' . $method . ' ' . $uri . ' php=' . PHP_VERSION);
```

Now we could see the request arrive but never reach dispatch. The server accepted it, started processing, and then ... silence.

Step two: we verified the shared hosting allocation limits. WebSupport.sk shared hosting has a **128–256 MB RAM** limit per PHP process. Argon2id with default settings needs **64 MB just for one hash**. With two concurrent requests, the server is dead.

## The fix: bcrypt

I switched `PASSWORD_ARGON2ID` to `PASSWORD_BCRYPT`:

```php
// PasswordHasher.php ... after the change
public const string ALGORITHM = PASSWORD_BCRYPT;
```

Bcrypt needs **≈4 MB RAM** ... 16 times less. Security stays excellent (bcrypt is still Industry Standard), but the server stops crashing.

We also had to update the dummy hash for timing-safe comparison:

```php
// AuthService.php ... DUMMY_PASSWORD_HASH
// Before: $argon2id$v=19$m=65536,t=4,p=1$...
// After:  $2y$12$6hO.qcb0O30iOs2k/EReUumcibRsIzq2GbYObFalM7RPajh04fCrG
```

The dummy hash serves **timing-safe comparison** ... when someone enters a nonexistent username, it's verified against the dummy hash so the response time matches a real password check. Without this, an attacker could measure timing differences and guess whether a user exists.

## Another problem: session storage

The shared hosting had another issue ... `mkdir()` for the session directory failed silently without permissions. We added a fallback to `sys_get_temp_dir()`:

```php
$sessionPath = APP_BASE_PATH . '/storage/sessions';
if (!is_dir($sessionPath)) {
    if (!@mkdir($sessionPath, 0775, true) && !is_dir($sessionPath)) {
        error_log('[session] Failed to create session save path ... falling back to system temp.');
        $sessionPath = sys_get_temp_dir();
    }
}
```

And finally ... FPM crash logs. We added `ini_set('error_log', ...)` at the very start of `public/index.php`, before any bootstrapping. When FPM crashes mid-request, at least we know where to look.

## The lesson

**An algorithm isn't just about security ... it's about fit for infrastructure.**

Argon2id is objectively a better algorithm than bcrypt. But "better" means "better for a server that has enough resources." On shared hosting with 128 MB RAM, bcrypt is the right choice ... and argon2id is a UX catastrophe.

Before you use any compute-heavy algorithm, verify:

1. **Allocation limits** of your hosting environment
2. **Concurrent connection count** (FPM worker limit × RAM per worker)
3. **Whether the algorithm has a graceful degradation path** ... bcrypt does, argon2id doesn't

And if you're on shared hosting ... just use bcrypt. It's not a compromise, it's a pragmatic decision.

Argon2id remains a superior algorithm. On a VPS with 2 GB RAM, it's the right choice. On shared hosting with 128 MB, it's a UX catastrophe. Know your infrastructure before you choose your cryptography.

---

Security and operability must go together. An algorithm that crashes the server isn't secure, it's dangerous.

---

→ [CSP and onclick](/blog/csp-onclick-silent-security-bug): the silent security hole that killed inline handlers
→ [Cache wrappers](/blog/cache-wrappers-good-servant-bad-master): good servant, bad master

## Sources

- [PHP Manual](https://www.php.net/manual/): official PHP documentation
- [PHP-FIG, PSR Standards](https://www.php-fig.org/): PHP standards
- [OWASP Cheat Sheet Series](https://cheatsheetseries.owasp.org/): security best practices
- [Nativa Minimal Documentation](/docs/getting-started): official getting started guide
- [W3C, Web Standards](https://www.w3.org/standards/): web standards
