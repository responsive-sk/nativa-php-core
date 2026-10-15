# 10 — The auth provider nobody had to list (2026-10-16, the deferred half of §6)

§6 wired the `/mark` gate explicitly and deferred `#[AsAuthProvider]` discovery
for a stated reason: the registry would have to construct a middleware with
dependencies. That reason is §6's, not this round's — a PROVIDER is a port
implementation with a zero-argument constructor (`UsersAuthProvider` was built
that way on purpose), so a registry can instantiate it while `AuthMiddleware`,
which takes the provider, stays explicitly wired. This round does exactly that.

Two facts decided the shape before any code was written, both measured:

- the attribute had **0 readers** since `93b09e3` deleted `AuthProviderRegistry`
  as dead code, and
- the two-line wiring existed in **three copies** (`public/index.php`,
  `KernelOnlyBootTest`, `AdminAccessControlTest`), which is the drift the
  single shared address was invented to prevent and which no test could catch,
  since all three were the same code.

## What shipped

| Piece | Before | After |
|---|---|---|
| `src/Modules/Core/Application/Services/AuthProviderRegistry.php` | deleted (`git show 93b09e3^`) | **resurrected at its former path**, rebuilt on the CURRENT registry shape |
| `modules/Users/Domain/UsersAuthProvider.php` | `Domain`, import of `Interfaces\HTTP\AuthServices` | moved to **`Interfaces/HTTP/`** (the registry's published file-name contract), import inverted |
| `modules/Users/Interfaces/HTTP/AuthWiring.php` | the one explicit wiring address | **deleted** |
| `public/index.php` | `class_exists(AuthWiring::class)` + `AuthWiring::wire()` | `AuthProviderRegistry::first()` — **no module class named for auth** |
| the two test mirrors | `class_exists` + `wire()` (a copy each) | the same two Core-only lines as the root |

**The registry is the concept, not the old code.** Its `resolveFqcn()` is the
declaration-start version Boot 23 hardened — the deleted copy took the FIRST
mention of `class` anywhere in the file, which resolves a type named `says` out
of a docblock sentence and silently skips the file — and same-priority
contention votes through `PreferenceVote::select()`, the rule every other
registry already shares. `tests/Discovery/TextFirstFqcnTest` now pins this fifth
copy: a shape shared by five files is a shape that will be fixed in four of them.

Three decisions, each a rule rather than an accident:

- **One provider, not a merged answer.** Unlike the shell port (where two modules
  legitimately answer different halves), there is no half of "who is this
  request" a second module could own: two answers is not depth, it is ambiguity.
  Lowest priority wins; a tie keeps the first and LOGS the rest.
- **Zero-argument constructors are enforced, not documented.** Discovery
  instantiates at scan time, so a provider that took a dependency would capture
  one from the wrong moment — the harness swaps `DB_*` between cases and the
  captured connection would outlive its database. `getNumberOfRequiredParameters()`
  refuses it; the module's provider resolves services lazily instead.
- **A provider that throws is NOT caught** (same rule as the shell registry): an
  auth answer that disappears quietly is a gate nobody can trust.

## The governance question the plan left open

The plan asked whether the resurrected file's Inventory row should be corrected
(`dead` → `runtime`) or whether a Movement entry alone suffices, defaulting to
"correct the row AND add the entry". **The document's own rule wins:**
`docs/kernel-shape.md` states that the baseline does not move and that rows stay
at their baseline values even where the tree no longer holds the file. A
resurrection is movement, so the row keeps its baseline fact (`dead`, 5 366 B)
and the dated Movement row records the file being alive again — with its new
size, bucket and importers. Correcting the row would have quietly rewritten the
baseline the whole table is measured against.

## Two traps this round walked into (both recorded, neither silent)

1. **A glob inside a docblock closed the comment.** Writing the registry's
   contract as prose — `` `*/Interfaces/HTTP/*AuthProvider.php` `` — put a
   literal `*` `slash` pair inside the comment, which ends it early: every
   following line became code, and the moved provider died with
   `syntax error, unexpected token "#["`. `ShellContextProviderRegistry` already
   warns about exactly this in its own docblock. The suite caught it in 13
   errors; the fix is a wording, not an escape.
2. **A symlinked `vendor` broke the kernel-only worktree, not the change.** The
   first kernel-only run failed `ModuleLifecycleTest` with
   `file_get_contents(): … Is a directory`: that test hashes the whole tree and
   `RecursiveDirectoryIterator` does not follow symlinks, so the fixture
   `vendor` link was read as a file. Proved by a CONTROL worktree at `HEAD`
   without this round's changes — it failed identically. `cp -a vendor` instead
   of `ln -s vendor` turned the real run green.

## Verified (2026-10-16, all exit 0 unless stated)

| Gate | Result |
|---|---|
| `composer test` (full suite) | **315 tests, 1323 assertions**, 1 skip (289 → 315: the registry's 5 discovery contracts + the mirrors) |
| `composer phpstan` | level 9, no errors |
| `composer cs-fix-dry` | 0 of 94 files fixable |
| `composer rector-dry` / `make lint` | Rector OK / lint clean |
| `make lekalo-check` | 23 rules, 0 errors, 0 warnings; `lock --check` unchanged (`sha256:e77647f7…`) |
| `make extract-check` | OK — 222 identical, 66 recorded divergences, **plus the new `OWNED` row** for the registry (found by running the check, not by guessing the entry shape: it reported `exists only here (not OWNED)` first) |
| `make kernel-size` | 317 447 B / 72 files, delta −20 745 B; Core types 10 interface / **60 concrete** — the one-line `MAX_APPROVED` 59 → 60 raise the shape test exists to make visible |
| kernel-only worktree (this round's files, `cp -a vendor`) | `composer phpstan` clean; `composer test` 315 tests, 240 skipped, 0 failures — the mirrors now name only Core classes, and the registry answers `null` with no module installed |
| live `curl`, `php -S 127.0.0.1:8201` (real session + real `_token` from `GET /login`, two seeded probe users) | anon `/mark` → **302 `/login`, 0 B**; anon `/mark/users` → **302 `/login`, 0 B**; `/` → 200; admin login → 302 `/mark`, then `/mark` → **200 (34 646 B)**, `/mark/users` → **200 (37 663 B)**, `/login` → **302 `/mark`** (guest-only); identity WITHOUT the permission `/mark` → **403 (53 B)**, `/mark/users` → **403 (54 B)** — identical to the explicit wiring §6 measured |
| `[auth]` log line, live | `[auth] wired Modules\Users\Interfaces\HTTP\UsersAuthProvider` — the discovered class is named, from its new location |
| mutation checks (3, each reverted byte-identical) | disable the zero-arg refusal → the refusal test errors; disable the contention logging → the tie test fails with the missing line in the diff; remove BOTH the text gate and the attribute check → the unmarked-file test errors. (Removing either gate ALONE changes nothing — two independent gates guard the label, which is worth knowing when reading that test.) |
| module install trap (§7/§8/§9) | `module:remove Users --force` + `module:add Users`; `diff -rq modules/Users src/Modules/Users` empty; the cascade into Comments/Questions left both identical (11/11 and 10/10 files) |
| probe cleanup | probe users deleted (`users` back to 3, 0 `probe-%` rows, their `sessions`/`user_roles`/`login_attempts` rows with them); the probe server stopped; both probe scripts lived in `/tmp`; `git worktree list` back to the single main tree |

---

# 11 — A real transport for reset links, and the fallback that stays forbidden (2026-10-16)

§7 removed the leak and left an honest dead end: in production the null mailer
was the only implementation, so a reset link reached nobody, and the docs said
"install a `PasswordResetMailer` implementation". This round ships the
implementation — **a generic HTTP transport** — because the alternative ends a
claim this tree makes everywhere else: the kernel has ZERO Composer runtime
dependencies and CI boots it without `composer install`. A provider SDK would
make reset links undeliverable from a dependency-free tree; one POST to a
configured endpoint does not.

## What shipped

| Piece | What it does |
|---|---|
| `modules/Users/Infrastructure/Mail/HttpPasswordResetMailer.php` | one HTTPS POST of `{"from", "to", "subject", "text"}` with `Authorization: Bearer <key>` (omitted when no key is set); the text carries the clickable link |
| `PasswordResetMailerFactory::http()` | the ONE place the three keys are read, and the place a HALF-configured transport is refused |
| `PasswordResetMailerFactory::create()` | outside the dev whitelist: **configured HTTP transport, else null** — the §7 behaviour, with a way out of it |
| `.env.example` | `MAIL_API_URL` / `MAIL_API_KEY` / `MAIL_FROM` / `APP_URL`, commented out, each with what it is for and what happens while it is unset |

## Four decisions, each with a reason worth keeping

1. **The contract is documented, not vendor-shaped.** The body is the lowercase
   `{from,to,subject,text}` shape most transactional APIs accept (Resend,
   SendGrid's personalizations JSON, Plunk, Loops). An endpoint with a different
   body shape (Postmark's `From`/`To`/`TextBody`) needs a second implementation
   next to this one, not a flag inside it — the factory has one branch to grow.
2. **Delivery failure is logged, never thrown, and never falls back to the
   log.** A dead transport must not turn a reset request into a 500, and must
   NOT "helpfully" park the link in `storage/logs/app.log` — that fallback is
   audit finding #1 itself. The line names the address, the endpoint and the
   status; the token never appears in it.
3. **`APP_URL` is required, and the link is not derived from a request.** The
   port carries no `Request` by contract, and building a reset link out of a
   `Host` header the caller controls is a phishing surface, not a convenience.
4. **All three keys or nothing.** A half-configured deployment (endpoint and
   sender, no base URL) resolves to the null mailer and LOGS which key is
   missing — a link built on a missing base URL is worse than no link, and a
   silent skip would leave "mail is configured, why is nothing arriving"
   unanswerable.

## Verified (2026-10-16, all exit 0)

| Gate | Result |
|---|---|
| `composer test` (full suite) | **318 tests, 1339 assertions**, 1 skip (315 → 318: the three new transport cases) |
| `composer test --filter PasswordResetMailer` | 8 tests, 47 assertions |
| `composer phpstan` | level 9, no errors (the stream-context closure and the response-header parse included) |
| `composer cs-fix-dry` / `composer rector-dry` / `make lint` | 0 of 94 fixable / Rector OK / lint clean |
| `make lekalo-check` | 23 rules, 0 errors, 0 warnings |
| `make extract-check` | OK — 222 identical, 66 recorded divergences (`.env.example` is in `SLICE_SKIP`, so the new keys need no divergence row) |
| failure path, through the REAL route (`APP_ENV=production`, `MAIL_API_URL=http://127.0.0.1:9/nowhere` — a port nothing listens on, so the refusal is real, not mocked) | HTTP 200, token row written, `storage/logs/app.log` unchanged, one log line: `[mailer:http] reset link for … rejected by http://127.0.0.1:9/nowhere (status 0)` |
| mutation check (1, reverted byte-identical) | moving the forbidden fallback back in — a `file_put_contents` of the link into `app.log` when delivery fails — fails `aFailingTransportKeepsTheFlowNeutralAndNeverFallsBackToTheLog` with the leaked link in the diff (the §7 finding's own shape); `storage/logs/app.log` did not exist before or after |

## What is deliberately NOT here

- **No SMTP client.** SMTP without a library means hand-rolling a protocol
  dialogue in a starter kit; the HTTP contract covers the providers that matter,
  and one implementation is enough to prove the port end to end.
- **No retry queue.** A failed delivery is logged, not retried — the Queue
  module exists, and wiring the mailer into it is a change with its own design
  (idempotency, who owns the job, what the audit trail is), not something to
  smuggle into a transport.
- **No change to the neutral success message.** It says a link has been sent
  both when a transport delivered it and when none is configured, because the
  alternative is an enumeration oracle. §7 argued this; it still holds.
