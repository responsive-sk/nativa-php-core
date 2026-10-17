# Name ownership — one name, one owner

Ledger for `tests/Architecture/NameOwnershipContractTest.php`. Every number
here is **measured**, and the test fails when a number and the tree disagree.
A row that is not measured is a wish.

The rule the gate cannot check is worth stating too: a class constant is a
**good** tool for most of this tree. 240 declarations, and the large majority
are private technical invariants — `CoverGenerator::WIDTH`, `MAX_RETRIES`,
`IDLE_TIMEOUT` — which are facts about the code, not configuration and not
identifiers. Those are correct as they are and the gate leaves them alone. The
gate is about the small minority where a constant is standing in for something
it should not be.

## What is counted, and what is not

Scope: `src/Modules/{Core,Database,KernelTheme,Tooling}`, `src/Themes`,
`modules`, `tests`, `bin`, `public` (minus `public/dist`). 407 files.

`src/Modules/<Name>` for any other module is **gitignored operator state**. A
kernel-only checkout carries Core + Database and nothing else, so a gate
scoped at the whole tree would report different numbers depending on which
modules happen to be installed — the defect
`ModelCatalogueContractTest::aRemovedModuleCannotLeaveItsModelBehindQuietly()`
was fixed for two commits ago. Measured on both shapes, the numbers are
identical:

| | full tree | kernel-only |
|---|---|---|
| files | 407 | 407 |
| declarations (rule 3, excluding the gates) | 240 | 240 |
| distinct names (rule 3) | 203 | 203 |
| values with 2+ names (rule 1) | **2** | **2** |
| literal places (rule 2) | 532 | 532 |

## Rule 1 — one string value, one name · absolute, 2 exemptions

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

## Rule 2 — a value spelled as a literal outside its owner · ratchet at 532

A **ceiling, not a list.** 55 owned string values appear as raw literals on
**532 places** across the tree. An absolute rule here would need 532
exemptions, and a gate with 532 exemptions is not a gate:

| filter | values | places | files |
|---|---|---|---|
| none | 55 | 532 | 201 |
| length ≥ 4 | 50 | 456 | 194 |
| length ≥ 8 | 30 | 228 | 88 |
| contains `/` or `.` | 14 | 168 | 52 |
| contains `/` | 5 | 113 | 41 |

The leading values are `'en'` (62 places), `'home'` (54), `'sk_SK'` (49). `'en'`
is the default language code; it is spelled as a literal 117 times in the tree
and belongs in no constant anyone would name. The largest single source is
`Locales/…/SeedData.php` with **112 places on its own** — seed data is not a
constant, and converting it would be worse rather than better.

So the only legal direction is down. Every consolidation in Phase 2 lowers the
number, and the gate refuses the 533rd.

### Places that are counted on purpose and must stay counted

Seven of the 532 are test literals that **look** like duplication and are not.
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

### The bigger number is mostly data

The largest single value is `'sk_SK'` on **132** places, then `'en'` (48) and
`'home'` (36). These are a locale code, a default language and a route slug —
data that appears in seed data, translations and URL tests. Turning `sk_SK`
into `LocaleService::SK_SK` would be a tautology dressed as a refactor. The
ceiling counts them because counting them is honest; the consolidation work in
Phase 2 does not touch them, and should not.

## Rule 3 — the declaration count does not rise · ratchet at 240 / 203

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

Rules 1 and 2 still read all of those files, so a duplicated name in a gate is
still caught. Only the count is exempt.

The ceilings moved together twice, and both numbers carry the reason at the
constant in the test. Up, once: 241 → 243 and 203 → 205 for
`ModuleTreeState::IGNORED`, the list of directory prefixes excluded from a drift
comparison — one constant that made the comparison correct in one reader instead
of two. Down, once, and further: 243 → 240 and 205 → 203, because task 2.6
removed three declarations rather than adding one. That is the only direction
this ratchet is ever supposed to move, and it moved on its own.

## How the counting is done, and three ways to get it wrong

The number is the test, not a comment in it. A regex over this tree finds **6**
constants, not 414, because the tree writes typed ones
(`private const string ADMIN_PATH = '/mark';`). Regex is also wrong in the
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