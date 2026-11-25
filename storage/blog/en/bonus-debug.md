---
title: "Member bonus ... debug: retrospection instead of live exposure"
date: "2026-09-17"
version: "1.0.0"
tags: ["debug", "php", "security", "observability", "architecture"]
excerpt: "The sixth bonus for registered readers. The debug panel is a weapon with a safety catch: 404 instead of 403, env masking before display, a snapshot taken after the request ends. Why an 'always-registered route that does not exist' beats a secret path — on real code from this repo."
author: "Nativa Dev"
author_email: "nativa.dev@example.test"
category: "Bonus"
members_only: true
translation: "bonus-debug-pre-deti"
published: true
---

> This is the sixth **member bonus**. Previous parts: [the members-only
> lock](/en/blog/bonus-members-only), [the immutable audit
> log](/en/blog/bonus-audit-log), [full-text search via
> FTS5](/en/blog/bonus-full-text-search), [comments with
> moderation](/en/blog/bonus-comments) and [media as foreign
> files](/en/blog/bonus-media). Today: the tool that sees everything
> ... and in production doesn't exist.

You're a developer. The app in production starts doing something
nonsensical.

First thought: check the log. Second: spin up a debug panel. Third —
and this is the right one: *a debug panel running in production is a
security hole with a flashlight.*

This kit solves it differently. The `/debug` panel is a weapon with a
catch — and every detail of its design is that catch.

## Always registered, never existing

The `/debug` route is always registered. Only the response changes with
the environment:

```php
// src/Modules/Debug/Interfaces/HTTP/Actions/DebugAction.php — the catch
if (!Env::bool('APP_DEBUG', false)) {
    return 404; // via the not-found page
}
```

Why 404 and not 403? Because 403 is a confession.

404 says "this address isn't here" — and for an attacker that's the
truth. In production the panel doesn't exist. 403 would say "it exists,
I just won't give it to you" ... which is a map, not a wall. A path an
attacker knows exists is a path they will keep circling.

For the same reason `robots.txt` never mentions `/debug` — the
allow-whitelist lists public surfaces only. Secret paths don't get
mentioned. First rule of the bunker: a non-existing object is not
defended.

## The panel sees everything ... except secrets

When the panel runs (dev), it shows: the request, the matched route,
env variables, open DB connections, the SQL log, phase timings.

And env variables are exactly where a panel can shoot itself in the
foot. `.env` carries passwords, tokens, DSNs — the whole keyring of the
house.

So the panel doesn't print env. It prints env **after censorship**:

```php
// DebugAction.php — keys that are never displayed
private const array SECRET_FRAGMENTS = ['PASS', 'SECRET', 'TOKEN', 'DSN', 'AUTH', 'KEY'];

private function isSecretKey(string $key): bool
{
    return array_any(
        self::SECRET_FRAGMENTS,
        static fn (string $fragment): bool => str_contains(strtoupper($key), $fragment),
    );
}
```

The logic is the opposite of what you'd expect. There's no list of
"show these, hide those" — there's a list of fragments that **always**
hide. `DB_PASSWORD`, `API_TOKEN`, `AWS_SECRET_KEY` ... all fall into
the net by name.

Be honest: masking by name isn't perfect. A key named `HESLO_K_DB`
would slip through. That's why the panel is never left on in
production — masking is the second line, not the first.

## Retrospection instead of a live feed

The third catch is the subtlest and the most interesting.

The panel doesn't observe its own request. It observes **the previous
one**:

```php
// DebugAction.php — the snapshot pattern
// The panel renders the LAST captured request — the /debug request
// itself collects nothing (its log and connections were reset at the
// start of this request by DebugServices::enable()).
$snapshot = DebugServices::lastRequest();
```

Why? Because a panel collecting data about itself measures itself. The
SQL log would contain the panel's own queries. The timings would belong
to rendering the panel. Diagnostics reporting on diagnostics — the
classic observer blindness.

The snapshot is taken by `public/index.php` after a normal request
completes. The panel then reads a closed past — a finished story with a
complete SQL log and timings, into which nothing can be appended.
Exactly the principle from the [state out of
context](/en/blog/state-out-of-context) article: state is written to
disk, read later, outside the event.

## The debugbar with a catch of the same kind

In dev, a floating bar is injected into HTML responses — Time, Memory,
DB, a link to `/debug`.

It carries the same three catches. It's injected only when
`APP_DEBUG=true` and the response is HTML. Every inline script carries
the CSP nonce — `SecurityHeadersMiddleware` substitutes it per
request, so the bar cannot open a hole in the Content Security Policy.
And when debug is off, the middleware is pass-through — zero extra
bytes.

Debug tooling that required disabling CSP would be a bad solution even
if it worked.

## The SQL log as a by-product

How does the panel see queries? Not through interceptors or magic.

`DebugServices::enable()` installs `LoggingPdoStatement` on new
connections — every `execute()` notes down its SQL and its time.
Regular code changes nothing: it uses PDO as always. Only underneath
runs a statement that takes notes.

| approach | cost | risk |
|---|---|---|
| ORM with event listeners | a whole ORM runtime | vendor lock |
| wrapper around the whole PDO | reimplementing the API | drift |
| statement subclass | one file | minimal |

The third path is in the spirit of the whole kit — a minimal touch at
the right place.

## When it doesn't hold (the mandatory honesty)

A debug panel is not monitoring.

It doesn't aggregate metrics, doesn't graph, doesn't send alerts. It's
a magnifying glass over one request — you look through it when
searching, not all the time. Production observability is a different
discipline with different tools.

The second honesty: the snapshot pattern has a price. The panel shows
the past — if you're asking about the request running right now, you
see the previous one. There's a fallback for live debugging of one
request, but the principle stays: diagnostics is not part of the event
it describes.

## Practically

1. **Enable debug locally** ... `APP_DEBUG=true` in `.env`, the panel
   at `/debug`, the bar in every HTML response.
2. **Check the masking** ... add a test key with `TOKEN` in the name
   to `.env` and see what the panel shows. It should show `***`.
3. **Never in production** ... `APP_DEBUG=false` is the only correct
   value. The panel there isn't hidden — it doesn't exist.

## Sources

- [OWASP — Authentication Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Authentication_Cheat_Sheet.html) ... why error responses must not betray the surface
- [MDN — Content Security Policy](https://developer.mozilla.org/en-US/docs/Web/HTTP/CSP) ... a nonce per request
- [PHP — PDOStatement](https://www.php.net/manual/en/class.pdostatement.php) ... the base LoggingPdoStatement extends
- [Nativa Minimal Documentation](/docs/getting-started) ... this kit's official getting started guide
- [docs/blog-voice.md](/docs/blog-voice) ... the voice guide where the bonus series lives
