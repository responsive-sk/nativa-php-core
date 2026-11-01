# Name ownership > one name, one owner

Ledger for `tests/Architecture/NameOwnershipContractTest.php` and the theme
half of the same rule in `tests/Theme/ThemeClassOwnershipTest.php`. Every
number here is **measured**, and the test fails when a number and the tree
disagree. A row that is not measured is a wish.

The rule the gate cannot check is worth stating too: a class constant is a
**good** tool for most of this tree. 260 declarations, and the large majority
are private technical invariants — `CoverGenerator::WIDTH`, `MAX_RETRIES`,
`IDLE_TIMEOUT` — which are facts about the code, not configuration and not
identifiers. Those are correct as they are and the gate leaves them alone. The
gate is about the small minority where a constant is standing in for something
it should not be.

## What is counted, and what is not

Scope: `src/Modules/{Core,Database,KernelTheme,Tooling}`, `src/Themes`,
`modules`, `tests`, `bin`, `public` (minus `public/dist`). 523 non-gate files
plus the 18 gate files — 541 scanned: rule 1 reads all of them, while the
two counts exclude the gates for the reason below.

`src/Modules/<Name>` for any other module is **gitignored operator state**. A
kernel-only checkout carries Core + Database and nothing else, so a gate
scoped at the whole tree would report different numbers depending on which
modules happen to be installed — the defect
`ModelCatalogueContractTest::aRemovedModuleCannotLeaveItsModelBehindQuietly()`
was fixed for two commits ago. Measured on both shapes, the numbers are
identical:

| | full tree | kernel-only |
|---|---|---|
| files (rule 1 scan) | 541 | 541 |
| declarations (rule 3, excluding the gates) | 260 | 260 |
| distinct names (rule 3) | 210 | 210 |
| values with 2+ names (rule 1) | **2** | **2** |
| literal places (rule 2) | 384 | 384 |

## Rule 1 > one string value, one name · absolute, 2 exemptions

Two names for one string value means two owners, and nothing reports the
rename: one of them is a permission nobody can ever be granted, a theme
nothing resolves, a colour that drifts from its twin.

**Strings only.** `60` is claimed by nine names — `TIMEOUT`, `PER_PAGE`,
`LIMIT`, `GENERAL_LIMIT`, `RATE_WINDOW`, `SESSION_DB_WRITE_INTERVAL`,
`DEFAULT_LOGIN_WINDOW`, `MAX_APPROVED` — and `5` by seven. That is not a second
owner; it is the same number meaning different things (60 seconds, 60 per
page, 60 rows). The difference is whether two matches name **the same thing**.
`'users.manage'` is an identifier and does; `60` is a number and does not.

Task 2.6 took this from five to two, and the three that went are worth naming
because **two of them were not what the exemption claimed**:

| value | names before | verdict |
|---|---|---|
| `'users.manage'` | `USERS` (Users) · `REQUIRED` (tests/Http) | **gone.** `Permissions::USERS` is the owner and the kernel owns it. The test imported it — and while doing so a fourth spelling turned up in the same file (`new AuthIdentity('u-42', false, ['users.manage'])`, the one place that bypassed the constant the four others used), which is what a second owner produces |
| `'#94a3b8'` | `DIFF_CONTEXT_INK` · `VERSION_INK_MUTED` | **gone.** Not "two roles in one palette" — one class declaring the same colour twice, and the alias used it ONCE (the dev-version stamp). `DIFF_CONTEXT_INK` is the member of the `DIFF_*` set, so the alias is gone |
| `'griffe'` | `BUNDLE` · `THEME` | **gone.** `GriffeTheme::name()`/`::bundle()`/`::jsKey()` all return it; that class is the owner. `GriffeBuildFreezeTest` asked the owner instead of declaring it, which makes the freeze STRONGER: renaming the bundle without re-freezing now fails that test |
| `'Cardboard'` | `ADMIN_MODULE` · `MODULE` | **stays.** See below |
| `'203.0.113.7'` | `CLIENT_IP` · `REMOTE` | **stays**, and its old reason was simply false |

**Why `'Cardboard'` stays.** Two tests that each guard their own skip, in
different scopes: `KernelOnlyBootTest` is in `tests/Boot`, `SettingsThemeSelectTest`
in `modules/Cardboard`. Zero of the module's tests import from `tests/`, and the
first would set a precedent `KernelCouplingTest` would then have to know about.
There is also no owner to consolidate onto — nothing outside `tests/` writes
`'Cardboard'`.

**Why `'203.0.113.7'` stays.** The exemption named
`AuthMiddlewareDenialTest::REMOTE`, and that constant does not exist: the file
owns `203.0.113.9`. The real second claimant is `AuthServiceTest::REMOTE`, in a
different module. The value is an RFC 5737 documentation address used as a
SCENARIO, not an identifier — `PasswordResetServiceTest` walks
`203.0.113.1/.2/.3` precisely to prove one address gets three attempts, so the
octet carries meaning. A shared `tests/Support` constant was measured and
rejected: it would claim two runs are the same run, and cross a module boundary
for zero runtime gain (`tests/` is `autoload-dev`; the objection is the
dependency, not speed).

**And one that had to stay inside the file that checks it.** Rule 2's ceiling
excludes `GATE_FILES`, because an exemption is keyed by the value it excuses —
so every remaining entry in `MULTI_NAME_EXEMPTIONS` is necessarily a literal in
this test, and a ceiling that the ledger's own reasons inflate can never fall
again. Recording a reason is what makes a collision tolerated; counting the
tolerance as duplication is a ratchet that tightens itself every time somebody
writes down WHY.

The list also fails **when an entry stops applying** — an exemption that
stopped being a collision is rot in the ledger, not a free pass.

## Rule 2 > a value spelled as a literal outside its owner · ratchet at 385

A **ceiling, not a list.** 50 owned string values appear as raw literals on
**385 places** across the tree (measured 2026-10-27). An absolute rule here
would need 385 exemptions, and a gate with 385 exemptions is not a gate:

| filter | values | places | files |
|---|---|---|---|
| none | 50 | 385 | 115 |
| length ≥ 4 | 50 | 385 | 115 |
| length ≥ 8 | 35 | 171 | 81 |
| contains `/` or `.` | 11 | 57 | 27 |
| contains `/` | 4 | 45 | 21 |

The `length ≥ 4` row is the `none` row today: every counted value is at least
four characters, quotes included.

The leading values are `'en'` (54 places), `'home'` (41) and `'/mark'` (30):
a bare language code, a route slug and the panel's mount path — data that
appears in seed data, translations and URL tests, and belongs in no constant
anyone would name. The largest single source is `Locales/…/SeedData.php` with
**28 places on its own** — seed data is not a constant, and converting it would
be worse rather than better. `'sk_SK'` is no longer among them: a `xx_YY` code
is data about a language and the ceiling skips it (`isLocaleCode()`), which is
what took the number from 526 to 384.

The ceiling moved 532 → 514 with task 2.2: reading `Question::STATUS_*` and
`Comment::STATUS_*` in the admin actions and the comments metric retired 18
owned literals. It moved 514 → 502 with task 0.3: the two `ADMIN_PATH`
constants and the three call sites that retyped `'/mark'` beside them are gone,
while `AdminRoutePolicy::DEFAULT_PREFIX` keeps the value owned — the remaining
`/mark` literals are still counted. It moved 502 → 499 with task 2.8: the 130
admin redirects now address routes by name, and the three that spelled the bare
`/mark` were the ones this rule could see. It moved 499 → 496 with task 2.9,
which did the same for HTML links and forms; the rest of both refactors is held
by `AdminRouteUrlNamesTest`, which scans `href`, `action` and HTMX URLs. That is
the only legal direction, and it moved as work rather than as an accounting
change.

Then 526 → 384 with the locale decision (2026-10-27): 142 places spelled a
`xx_YY` code, 84 of them in `Locales/…/SeedData.php`, and a locale code names a
language rather than this tree's default — rule 2 skips it (`isLocaleCode()`)
and those places left the count. Then 384 → 385 with the shared paginator
(2026-10-27): `GET /mark/notifications` is the first list paged by
`modules/Pagination`, and its one counted place is the action reading `?page=`
off the request. That is a URL key, and no constant can absorb it without
becoming the second name rule 1 exists to forbid — a rise of one on a new
surface is the honest measurement, and the direction is still down. The gate
refuses the 386th.

### Places that are counted on purpose and must stay counted

Seven of the 385 are test literals that **look** like duplication and are not.
They are `assertSame()` calls whose subject is the exact string that has to
arrive in the database row:

| place | value |
|---|---|
| `modules/Outbox/tests/OutboxAdminListTest.php:93` | `'article.published'` |
| `modules/Outbox/tests/OutboxMetricTest.php:110` | `'article.published'` |
| `modules/Outbox/tests/ProcessOutboxCommandTest.php:114,186` | `'article.published'` |
| `modules/Outbox/tests/ProcessOutboxCommandTest.php:115` | `'article.unpublished'` |
| `tests/Http/AuthMiddlewareDenialTest.php:169,175` | `'security.authz.denied'` |

Writing `assertSame(SecurityEvents::ARTICLE_PUBLISHED, $row['event_type'])`
instead would make the test **tautological**: it would pass even if the constant
had been changed to the wrong value, which is the one thing this tree cares
about. An assertion that cannot fail is not an assertion.

They stay in the ceiling on purpose. Removing them from the count would make the
number look better while the code gets weaker, which is the opposite of what a
ratchet is for.

### The biggest block is still data

The largest single value is `'en'` on **54** places, then `'home'` (41) and
`'/mark'` (30) — 125 of the 385. A bare language code, a route slug and the
panel's mount path: data that appears in seed data, translations and URL tests.
Turning `en` into `LocaleService::EN` would be a tautology dressed as a
refactor. The ceiling counts them because counting them is honest; the
consolidation work in Phase 2 does not touch them, and should not. The 132
places that spelled `'sk_SK'` are not counted at all, which is why this number
fell by 141 in one edit.

## Rule 3 > the declaration count does not rise · ratchet at 238 / 202

This is the rule that catches the cheapest wrong fix: *"there are two names for
this, let me add a third."* That is the complaint this whole ledger started
from.

The gate excludes **its own file** from this count, and so does every sibling
gate in `tests/Architecture`. It holds the ceilings, so counting its own constants
would mean the gate's next edit — including its own approval list — moves the
number it is meant to hold.

The exclusion is by name and covers more than one file, and that is a deliberate
widening rather than a convenience: a gate that exempts only itself can be
outgrown by writing another gate. `MetricRegistryResolutionTest` did exactly that
on the day it was written — one `REGISTRY` constant took the count from 241 to
242, and the honest fix was to exempt the gates, not to raise the ceiling. The
same move this rule exists to catch, wearing a different hat.

Rule 1 still reads all of those files, so a duplicated value declared in a gate
is still caught. The two counts — rule 2's literal places and rule 3's
declarations — exclude them, because the exemption reasons are themselves
literals spelled in the gate file.

The ceilings moved together three times, and both numbers carry the reason at
the constant in the test. Up, once: 241 → 243 and 203 → 205 for
`ModuleTreeState::IGNORED`, the list of directory prefixes excluded from a drift
comparison — one constant that made the comparison correct in one reader instead
of two. Down: 243 → 240 and 205 → 203, because task 2.6 removed three
declarations rather than adding one. Down again: 240 → 239 and 203 → 202, when
the theme gate's `UNPREFIXED_ALLOWED` list was deleted — PHPStan had proved the
empty array could never match a class, so it was a constant guarding nothing.
Down once more: 239 → 238 when task 0.3 deleted the Settings provider's
`ADMIN_PATH` constant and `AdminRoutePolicy` gained `DEFAULT_PREFIX` in its
place — one default for one panel, not two spellings of one name. The distinct
name count stayed 202: one name left and one arrived.
That is the only direction this ratchet is ever supposed to move, and it moved
on its own.

## Rule 4 > a CSS class belongs to one theme · measured 2026-10-17

Same rule, different vocabulary: a class a theme defines is a name the theme
owns. `tests/Theme/ThemeClassOwnershipTest.php` reads every stylesheet a theme
returns from `criticalCss()` and fails when one class is defined by two themes,
or when an own class does not carry that theme's `prefix()`.

The plan named four themes (`mo-`, `je-`, `er-`, `gr-`). Discovery found
**five** — the kernel theme carries `k-` and would have gone unmeasured — and
`boson` (`bo-`) joined later, which is why the table is measured rather than
maintained:

| theme | prefix | bundle | classes | files |
|---|---|---|---|---|
| `boson` | `bo-` | `boson` | 198 | 1 |
| `erik` | `er-` | `erik` | 46 | 1 |
| `griffe` | `gr-` | `griffe` | 49 | 1 |
| `jednotka` | `je-` | `jednotka` | 106 | 9 (kernel `BASE_CRITICAL_CSS`) |
| `kernel` | `k-` | `kernel` | 17 | 1 |
| `mono` | `mo-` | `mono` | 196 | 2 |

617 class definitions (595 distinct names — the syntax-highlight markers are
counted in every sheet that defines them), and exactly **one** shared family:
the syntax-highlight markers. Griffe defines 6 `.tok-*` classes, Mono 12 and
Boson 11; the families overlap, with different colours on purpose
(`.tok-comment` is `#8b8fa3` against `#94a3b8`).
They stay because the emitter is `code-highlight.ts`, which is not in this
repository — renaming the markers would break the highlighter, not fix a
collision. The exception is named in `SHARED_CONTRACT['tok']` with that reason,
and the test fails if the entry stops applying.

Three measurement traps were found the same way the constant count found its
own, and each is written into the test:

1. **`criticalCss()` is the only truth.** `jednotka` returns the kernel's nine
   files, so grepping `modules/Themes/Jednotka/assets/` reports it as owning
   nothing; it owns the kernel's 106 selectors instead.
2. **`bundle()` is not `prefix()`.** `FrontendLayout` writes
   `<body class="{$bundle}-shell">`. Griffe's bundle is `griffe`, so
   `.griffe-shell` is emitted and live. Erik's bundle is `erik` while its
   stylesheet spells the same rule `.er-shell` — a measured name mismatch, not
   a collision, left for the stylesheet's owner to rename or remove.
3. **The tracked catalogue is read first.** `src/Modules/` is gitignored
   operator state; reading it first let an installed copy answer for the tree
   and every assertion passed while the checkout disagreed with itself. The
   catalogue has two shapes now (`modules/<Name>/` and
   `modules/Themes/<Name>/`), and both are tried before `src/`. The installed
   tree has the same two shapes (`src/Modules/<Name>/` and
   `src/Modules/Themes/<Name>/`), and `ownerKey()` strips the group from both
   sides, so the same constant in the catalogue and its slice is one site, not
   two.

Three classes in a theme stylesheet are **dead** — no emitter in this
repository: `copy-btn` and `has-badge` (Mono, whose emitter is the absent
`code-highlight.ts`) and `navbar__actions` (the upstream name beside Mono's own
`mo-navbar__actions`). They are listed in `DEAD_CLASSES` rather than deleted,
because deleting visible-from-nowhere CSS is a product decision; the test fails
if one comes back to life, so the list cannot rot into a permission slip.

Negative control, run 2026-10-17: adding `.probe-collision` to `griffe.css` and
`mono.css` failed both assertions (and the prefix rule), so the gate is known to
fail for the tree it is measuring, not only to pass on it. Re-measure with:

```
vendor/bin/phpunit tests/Theme/ThemeClassOwnershipTest.php
```

On a kernel-only checkout the two collision assertions **skip** (one theme
cannot collide with anything); the prefix and dead-class checks still run
against whatever is installed.

## How the counting is done, and three ways to get it wrong

The number is the test, not a comment in it. A regex over this tree finds **6**
constants, not 414, because the tree writes typed ones
(`private const string MARKER = '.migrations-applied';`). Regex is also wrong in the
other direction: it matches constants inside comments, and this tree documents
its constants at length. So the test tokenises.

Three traps, each found by measurement rather than by reading — and **each of
them made the count too small**, which is the dangerous direction: a gate built
on a wrong number is green on a tree that violates it.

1. **`array` is `T_ARRAY`, not `T_STRING`.** A type detector that knows only
   `T_STRING` silently loses every `const array` — 88 here, most of the tree.
2. **An untyped `const NAME = …` has a name that is itself a `T_STRING`.** A
   loop that consumes `T_STRING` as "a type" walks past the name onto the `=`
   and drops the declaration. Six of them.
3. **Literals must not be indexed by line.**
   `assertSame('security.authz.denied', $row['event_type'])` puts two literals
   on one line; keying by line let the second overwrite the first and dropped
   exactly the values a test asserts — the ones rule 2 exists for.

Type tokens: `T_STRING`, `T_ARRAY`, `T_CALLABLE`, `T_STATIC`, `T_NS_SEPARATOR`,
`T_NAME_QUALIFIED`, `T_NAME_FULLY_QUALIFIED`, `T_NAME_RELATIVE`, plus `?` and
`|`. The name is a `T_STRING` matching `^[A-Z][A-Z0-9_]*$` immediately before
`=`.

## Re-measuring

Run the test. If a rule fails, the number in the message is the measurement and
the constant in the test file is the ceiling — raise the ceiling **with** the
reason in the commit message, the way `KernelShapeTest::MAX_APPROVED` and
`docs/kernel-shape.md` work, or lower the ceiling, which is the work.

Raising a ceiling is a reviewable edit. Lowering one is the point.