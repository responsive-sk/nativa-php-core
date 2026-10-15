# 7 — The reset token in the log (2026-10-15, security-audit finding #1)

The audit of the public routes and the CSRF flow left one medium finding that
mattered more than its label: `PasswordResetService::requestReset()` appended
the plaintext reset token — beside the address it belongs to — to
`storage/logs/app.log` on every request, **in every environment**, and the
success page named the file for the visitor. The comment above it said "dev
mailer"; the code carried no gate at all, and `.env.example` ships `APP_ENV`
that nothing in this flow read. Reading the log was enough to take over any
account that had asked for a reset.

Reproduced first, through the real route with `APP_ENV=production` (php -S,
port 8177 — the probe script lives in `/tmp`, not in the tree):

```
POST /forgot-password (APP_ENV=production) => HTTP 200
--- response alert ---
If an account exists for that email, a reset link has been logged by the dev mailer (storage/logs/app.log).
--- new app.log lines ---
[2026-10-15 23:30:10] [mailer:dev] Password reset for admin@example.test → token: <64 hex> (valid 1 h)
exit 1
```

## The fix: delivery moves behind a port

`PasswordResetMailer` (`Domain/Contracts`) owns delivery. Two implementations:

- `DevLogPasswordResetMailer` — the development transport: the same log line as
  before, with the log file **injected** rather than resolved inside, so the
  suite can assert what lands in it on a file the test owns;
- `NullPasswordResetMailer` — what production gets: nothing is delivered and
  nothing is written, while the answer stays exactly as neutral as before (no
  enumeration oracle either way). The drop is noted at `LOG_LEVEL=debug`.

`PasswordResetMailerFactory` is the ONE place that picks between them, from a
**whitelist** of environment names (`development`, `local`, `testing`, `test`,
`dev`) rather than "everything that is not production": `staging`, a typo, or
an environment nobody classified must not be the one that keeps a credential on
disk. `requestReset()` returns `void` now — the raw token leaves the service
only through the port, so no caller can decide to record it. `AuthServices::resets()`
composes the two, which is where the environment fact is read exactly once.

Two consequences worth naming:

- `APP_ENV` is now read by two gates (HSTS, this one), both treating unset as
  development — that default is what makes a fresh checkout work without a
  `.env`. `.env.example` now says what each value means for reset links, and
  which class to implement to deliver them in production (the kernel ships no
  mail transport). **Amended by §11**, which ships that class: an HTTP transport
  configured from `MAIL_*` + `APP_URL`, so "install one yourself" is no longer
  the end of the story.
- The success page names `storage/logs/app.log` only where the link really is,
  from the same `writesToLog()` the transport choice comes from. A production
  visitor is no longer pointed at — or told about — a log file.

**The trap this round walked into:** composer resolves `Modules\` to
`src/Modules/`, so the INSTALLED slice is what runs and what the tests load;
`modules/Users/…` on its own is invisible until re-installed. Verified with
`ReflectionClass::getFileName()`. The sync used the documented path —
`bin/cms module:remove Users --force` (refused otherwise: fourteen files across
Audit/Blog/Comments/Questions import Users) then `bin/cms module:add Users` —
and `diff -rq modules/Users src/Modules/Users` is now empty.

## Verified (2026-10-15, plaintext reset token, all exit 0)

| Gate | Result |
|---|---|
| probe, before the fix | exit 1 — `[mailer:dev] … token: <64 hex>` line in `storage/logs/app.log`, page naming it |
| probe, after the fix | exit 0 — HTTP 200, "…a reset link has been sent.", `(no new lines)` |
| `PasswordResetMailerTest` (new) | 5 tests green; mutation-checked — forcing `create()` to always return the dev transport fails the production case with the leaked token line in the diff |
| `composer test --filter PasswordReset` | 10 tests, 39 assertions, 0 failures |
| `composer test` (full suite) | 289 tests, 1215 assertions, 1 skip |
| `php -l` on the 9 changed files | clean |
| `composer phpstan` (gate scope) | level 9, no errors |
| phpstan level 9 on `src/Modules/Users` (informational — the gate excludes installed slices) | 2 errors, both pre-existing in `UsersAuthProviderTest` (committed on `feature/authprovider-discovery`, untouched here) |
| `php-cs-fixer --dry-run` on the changed files | 0 fixable (5 other `modules/Users` files were already unfixable-clean outside the config's paths) |
| `make lekalo-check` | 23 rules, 0 errors, 0 warnings — the `users.password_reset_flow` summary no longer claims the token is logged unconditionally |
| `make extract-check` | 224 identical, 64 recorded divergences (`.env.example` is in `SLICE_SKIP`, so its note is not a divergence) |

Cleanup after the probes: the token row the reproduction created for
`admin@example.test` was deleted and `storage/logs/app.log` (created by the
reproduction, carrying that live token) was removed — the tree is back to
`php-error.log` only. Servers stopped; the pre-existing dev server was never
running during this round.

---

# 8 — The comment form's bot defense (2026-10-15, security-audit finding #2)

**Finding #2 as first written was wrong in its first half, and the correction
is the useful part.** It claimed `POST /blog/{slug}/comments` carried "only the
global 60/min limiter". It does not: `CommentsService` rate-limits submissions
per **IP+slug** at 10 per 60 s (loopback exempt so local dev is not throttled),
applied BEFORE validation so a malformed payload cannot bypass it. What the
route really lacked, next to the tree's other public form (`QuestionSubmitAction`
— 5 per 300 s per IP plus a `question_website` honeypot), was the **honeypot**.

Measured before the change, through the real action on temporary databases
(`DB_COMMENTS`/`DB_USERS`/`DB_OUTBOX`, so the working tree's `comments.db` was
never touched):

```
POST /blog/hello-world/comments (every field filled, incl. comment_website) => HTTP 302 /blog/hello-world#comments
comments stored: 1
  row: hello-world | Bot Probe | pending
exit 1
```

A submission that filled every field it could find — the naive-crawler shape a
honeypot exists for — was accepted and left a `pending` row in the moderation
queue, indistinguishable from a reader's comment.

## What changed

- **`CommentSection::form()`** renders the field in the markup shape the
  questions block already uses (`<prefix>-comments__hp`, `hidden`,
  `autocomplete="off"`, `tabindex="-1"`, label "Website"): a honeypot nobody
  renders catches nobody, so it lives in the form the crawler is handed.
- **`CommentSubmitAction`** discards a filled `comment_website` BEFORE any
  lookup or write, and answers with the very redirect a real submission gets —
  the bot cannot tell which field gave it away and nothing reaches moderation.
  The malformed-payload case is answered the same way: "silently" is the
  contract, not a detail.
- The action's docblock now names both defenses and says which one is the
  boundary: the honeypot is a cheap filter for naive crawlers, the per-IP+slug
  limiter is what bounds the queue.

Deliberately **not** added: a second, per-IP-only limiter. The route already
sits behind the global 60/min per-IP middleware (`RateLimitMiddleware`, wired
whenever Security is installed — and Comments hard-depends on Security), so a
third number would be a redundant knob rather than a defense; the per-article
keying of the existing limiter is exactly what the global cap already covers.

## Verified (2026-10-15, comment honeypot, all exit 0)

| Gate | Result |
|---|---|
| probe through the action (temp DBs), before | exit 1 — `pending` row stored for a honeypot-filling bot |
| probe through the action (temp DBs), after | exit 0 — HTTP 302, `comments stored: 0` |
| HTTP probe (`php -S`, port 8178, `APP_ENV=production`, real `comments.db`) | form renders `name="comment_website"`; clean submission → 302 `…/{slug}#comments` and +1 row; honeypot submission → **identical 302**, +0 rows; the probe's row deleted afterwards |
| `CommentHoneypotTest` (new) | 4 tests, 16 assertions (the form field is asserted for BOTH theme vocabularies, `st` and `er`); mutation-checked on the final files — neutering the check fails both discard tests (`1 is identical to 0`, and 404 instead of 302 for the malformed payload) while the control stays green |
| `composer test` | 293 tests, 1231 assertions, 1 skip |
| `php -l` on the 3 changed files | clean |
| `php-cs-fixer --dry-run` on `modules/Comments` | my 3 files clean (`CommentsMetric.php` was already unfixable-clean outside the config's paths) |
| `composer phpstan` (gate scope) | level 9, no errors |
| `make extract-check` | 224 identical, 64 recorded divergences |
| `make lekalo-check` | 23 rules, 0 errors, 0 warnings |

The installed slice was re-copied after the edit (`bin/cms module:remove Comments
--force` + `module:add Comments`) — same trap as §7: composer resolves `Modules\`
against `src/Modules/`, so a catalogue edit alone is invisible to the suite.

---

# 9 — Password policy, submit throttles, the CSRF origin check (2026-10-16, security-audit findings #3–#8)

This round continued the audit §7/§8 started. The first thing it did was
re-verify every remaining premise against the code, and that mattered: §8 had
already caught finding #2's first half being wrong (it claimed the comments
route had no limiter of its own), so no finding was fixed on its own wording.

| # | Finding as first reported | Re-verified fact | Evidence | Verdict |
|---|---|---|---|---|
| 3 | password minimum is 8, baseline is 12 | TRUE — and it lived in **three** places, not one | `AuthService::register()` (`required|min:8|confirmed`), `PasswordResetService::reset()` (same), `Cardboard\…\UsersAction::validate()` (`required|min:8` / `min:8`); the message `'Password must be at least 8 characters.'` typed at all three; `lekalo/modules/users/policies.yaml` said 8 | confirmed, medium |
| 4 | open registration has no throttle of its own | TRUE — `register()` never touched the injected `RateLimiter`; only the global 60/min/IP middleware bounded it | `AuthService` (no limiter call on the register path; `isLoginAllowed()` is the only use) | confirmed, low-medium |
| 6 | `/forgot-password` has no per-email throttle | TRUE — no limiter anywhere on the path; each request DELETEs + INSERTs one token row, and a *distinct* address per request grows the table without bound | `PasswordResetRequestAction::submit()` → `PasswordResetService::requestReset()` | confirmed, low-medium |
| 7 | CSRF is token-only, no Origin/Referer cross-check | TRUE — no `origin`/`referer` read anywhere in the tree | `CsrfMiddleware` (global on POST/PUT/DELETE/PATCH, `hash_equals`, 403 on mismatch) | confirmed, low (defense-in-depth) |
| 8 | `audit.sh` dies under `set -e`; secrets regex false-positives | TRUE, with a root cause: `((ISSUES++))` returns exit **1** when the counter is 0, so the first branch entered killed the script — exit 1 after one section, no Summary, empty stderr | `.agents/skills/aif-security-checklist/scripts/audit.sh`, measured run | confirmed, tooling |

Sweep of the same surface (nothing else found): the public POST surface is
`/register`, `/login`, `/logout`, `/forgot-password`, `/reset-password`,
`/blog/{slug}/comments`, `/blog/{slug}/inline` (guarded by `ArticleWriteGuard`)
and `/questions/{type}/{slug}`; every other POST route sits behind the auth gate.
Login ✓ (5/min per IP+email), questions ✓ (5/300 s per IP), comments ✓ (10/60 s
per IP+slug) + honeypot ✓ (§8), registration and reset requests ✗ → fixed here.
`/reset-password` needs none: a 256-bit token is not brute-forcible and the
global middleware bounds the request rate (documented, not silently skipped).

## The password minimum: 8 → 12, and one owner for it

`Modules\Security\Domain\PasswordPolicy` is now the only place the number
appears (`MIN_LENGTH = 12`, the OWASP ASVS 2.1.1 baseline), with `minRule()` and
`tooShortMessage()` derived from it. The three call sites compose
`'required|' . PasswordPolicy::minRule() . '|confirmed'` and the message, so an
admin form can no longer accept what the public register refuses — which is what
"four copies of one policy" meant in practice.

**The owner is `Security`, not `Users`, and the ledger is why.**
`Cardboard`'s closure is `Outbox, Security, Settings, Validation` — `Users` is
NOT in it, even though `Cardboard` reaches `Users` classes through inline FQCNs
and `CardboardServices::need()`. Both consumers already depend on `Security`, so
the constant costs no new edge; anywhere else would have added one (visible or
invisible). The coupling ledger stays empty.

Length is the ONLY rule owned there, on purpose: NIST SP 800-63B and this tree's
dislike of decorative controls agree that a length floor is what a policy can
honestly demand, while character-class rules buy lockouts and a support burden.

## The two unthrottled public writes

`AuthService::register()` gained `$ip` and a per-address limit (5 accounts per
hour), `PasswordResetService::requestReset()` gained `$ip` and now returns
`array{ok, retryAfter}` (`CommentsService::submit()`'s shape). Both answer
**429 + `Retry-After`** through one new `ThrottledResponse`, deliberately with no
`Location` header — a refusal that also redirects has its body discarded by
`Response::send()`, so the client never reads why (`QuestionSubmitAction::refusal()`
is the sibling precedent).

Three properties worth naming, because each is a decision and not an accident:

- **The registration limit counts ACCOUNTS CREATED, not attempts.** It is asked
  after validation, immediately before the insert, so an honest reader who
  mistypes a password five times is not locked out of the form; mass account
  creation is bounded either way. Raw request rate stays the global middleware's
  business.
- **The reset-request limit is per caller *and* per target** (5/900 s per
  address, 3/3600 s per address-like key). An endpoint that mints a token for
  any address it is handed is otherwise a free amplifier for mail — or, in a
  tree with no transport, for log lines. It is asked BEFORE the token row is
  rewritten, so a refused request cannot invalidate the link the reader already
  holds.
- **The refusal says nothing about who exists.** Both keys are the caller's own
  inputs — the address they connect from, the address they typed — never whether
  an account exists, so a 429 answers identically for a registered and an
  unregistered email. The non-enumerability of the flow survives the throttle.

`Modules\Security\Domain\LocalClient` is the one owner of "the local developer
is not a spammer" (`isLoopback()`), and `CommentsService`'s private list folded
into it — three module limiters would otherwise have spelled the same three
addresses. `RateLimitMiddleware` keeps its own const, because Core may not name a
module; that constraint is worth more than one shared constant.

## The CSRF origin check (#7)

`Request::isSameOrigin(string $url)` compares a URL's HOST against the request's
`Host`, guarded exactly as `absoluteUrl()` guards it (with `TRUSTED_HOSTS` set,
an unrecognised Host resolves to the first allowed name, so a forged Host cannot
make a cross-site request describe itself as same-site). `CsrfMiddleware` calls
it with `Origin`, falling back to `Referer`.

The order inside `handle()` is token **first**, then origin — so a request
without a token is refused with the same 403 and the same message as before the
check existed, and no existing probe or integration changes meaning. Neither
header present → token-only: `curl`, a server-to-server call, a proxy that strips
both. `Origin: null` (a sandboxed document) and a malformed value name no host,
so they fail closed.

## Found by the sweep, deliberately not changed

`Request::getClientIp()` walks `X-Forwarded-For` from the RIGHTMOST hop and
returns the first address that is not a trusted proxy — the correct shape, a
spoofed leftmost entry is ignored. The one deployment where the loopback
exemption's reasoning lapses is worth stating: a reverse proxy **on the same
host** that is not listed in `TRUSTED_PROXIES` and sets no `X-Forwarded-For`
makes every request arrive from `127.0.0.1`, and the exemption then applies
production-wide — to the global middleware and every module limiter alike. The
answer is configuration, not code, so `.env.example` now says it next to
`TRUSTED_PROXIES` (an INFO item, no behaviour change).

## The audit script, and the vendored-copy drift

`.agents/skills/aif-security-checklist/scripts/audit.sh` (4 increments →
`ISSUES=$((ISSUES + 1))`, and the secrets pattern requires a quoted literal of
8+ characters) now reaches its own Summary: exit 0, six sections, empty stderr —
measured before/after on the same tree. The overshoot in the first attempt is
worth recording: `-i` was tried to catch camelCase `$apiKey`, and it re-flagged
`INVALID_TOKEN = 'passwords.token'`-style constants in `vendor/` (three in this
tree), so the camelCase spelling was added to the alternation instead. Probe on
a synthetic file: the old pattern reported 4 lines (3 of them code), the new one
reports exactly the 2 real literals.

`.agents/` is vendored, hash-managed tooling (gitignored, recorded in
`.ai-factory.json`). The fix is **local and the recorded hash is NOT rewritten**
— rewriting it would falsify the "pristine installed copy" record — so a future
`$aif` may flag or re-install the file. That consequence is the price of fixing
it here rather than upstream.

## Verified (2026-10-16, audit findings #3–#8, all exit 0)

| Gate | Result |
|---|---|
| `composer test` (full suite) | 310 tests, 1296 assertions, 1 skip (310 vs 293 at §8: the new policy/throttle/CSRF/USERS cases) |
| `composer phpstan` | level 9, no errors |
| `composer cs-fix-dry` | 0 of 92 files fixable (the gate's paths: `src/Modules/Core`, `src/Modules/Database`, `src/Themes`, `bin`, `public`, `tests`) |
| php-cs-fixer on the touched module files (informational — modules are outside the config's paths) | my 6 files clean; 7 pre-existing `modules/Users`+`modules/Comments` files still unfixable-clean |
| `php -l` on every changed/new file | clean |
| `make lint` | exit 0 |
| `make lekalo-check` | 23 rules, 0 errors, 0 warnings; `lock --check` unchanged (`sha256:e77647f7…`) — the lock does not cover descriptions/`applies_to`, so the policy edit moved no hash |
| `make extract-check` | OK — 222 identical, **66** recorded divergences (two new `DIVERGED` lines in `bin/build/kernel-divergence.txt` for `Request.php` and `CsrfMiddleware.php`) |
| live HTTP, `php -S 127.0.0.1:8199` (fresh, `APP_ENV=production`, real session + real token from `GET /register`) | cross-site `Origin` + **valid** token → 403 `Cross-site request rejected`; same-origin `Origin` + token → 200 (and the 12-character policy message); no token + cross-site → 403 `CSRF token validation failed` (**token decided first**); cross-site `Referer` with no `Origin` → 403; **neither header + token → 200**; `Origin: null` → 403; `storage/data/users.db` unchanged (no account created by any probe) |
| live throttle probe through the REAL actions, synthetic non-loopback `REMOTE_ADDR`, temp DBs | register: 5 → 302, 6th → **429, `Retry-After: 3599`**, exactly 5 rows stored; reset per target from four different callers: 3 → 200, 4th → **429, `Retry-After: 3600`**; reset per caller: 5 → 200, 6th → **429, `Retry-After: 900`**; loopback: 7 of 7 registrations accepted |
| mutation checks (5, each reverted) | hardcoding `min:8` in `register()` → `AuthServiceTest:140` fails; `LocalClient::isLoopback()` → `false` → 2 failures (`AuthServiceTest`, `PasswordResetServiceTest:193`); registration limiter → `true` → `AuthServiceTest:166` fails; `FORGOT_EMAIL_LIMIT = 30` → `PasswordResetServiceTest:179` fails; disabling the CSRF cross-check → 5 `CsrfMiddlewareTest` failures (the cross-site/`null` cases), with the same-origin, `Referer`, absent-header and token cases still green |
| module install trap (§7/§8) | `bin/cms module:remove <M> --force` + `module:add <M>` for Users, Security, Cardboard, Comments; `diff -rq modules/<M> src/Modules/<M>` empty for all four |
| probe cleanup | the `php -S` probe server stopped; both probe scripts live in `/tmp`, no probe rows in `storage/data/*.db`, `storage/logs/app.log` untouched (`APP_ENV=production` selects the null transport) |

---

