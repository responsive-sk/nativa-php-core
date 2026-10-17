# Name ownership — one name, one owner

Ledger for `tests/Architecture/NameOwnershipContractTest.php`. Every number
here is **measured**, and the test fails when a number and the tree disagree.
A row that is not measured is a wish.

The rule the gate cannot check is worth stating too: a class constant is a
**good** tool for most of this tree. 241 declarations, and the large majority
are private technical invariants — `CoverGenerator::WIDTH`, `MAX_RETRIES`,
`IDLE_TIMEOUT` — which are facts about the code, not configuration and not
identifiers. Those are correct as they are and the gate leaves them alone. The
gate is about the small minority where a constant is standing in for something
it should not be.

## What is counted, and what is not

Scope: `src/Modules/{Core,Database,KernelTheme,Tooling}`, `src/Themes`,
`modules`, `tests`, `bin`, `public` (minus `public/dist`). 403 files.

`src/Modules/<Name>` for any other module is **gitignored operator state**. A
kernel-only checkout carries Core + Database and nothing else, so a gate
scoped at the whole tree would report different numbers depending on which
modules happen to be installed — the defect
`ModelCatalogueContractTest::aRemovedModuleCannotLeaveItsModelBehindQuietly()`
was fixed for two commits ago. Measured on both shapes, the numbers are
identical:

| | full tree | kernel-only |
|---|---|---|
| files | 403 | 403 |
| declarations (rule 3, excluding this gate) | 241 | 241 |
| distinct names (rule 3) | 203 | 203 |
| values with 2+ names (rule 1) | 5 | 5 |
| literal places (rule 2) | 532 | 532 |

## Rule 1 — one string value, one name · absolute, 5 exemptions

Two names for one string value means two owners, and nothing reports the
rename: one of them is a permission nobody can ever be granted, a theme
nothing resolves, a colour that drifts from its twin.

**Strings only.** `60` is claimed by nine names — `TIMEOUT`, `PER_PAGE`,
`LIMIT`, `GENERAL_LIMIT`, `RATE_WINDOW`, `SESSION_DB_WRITE_INTERVAL`,
`DEFAULT_LOGIN_WINDOW`, `MAX_APPROVED` — and `5` by seven. That is not a second
owner; it is the same number meaning different things (60 seconds, 60 per
page, 60 rows). The difference is whether two matches name **the same thing**.
`'users.manage'` is an identifier and does; `60` is a number and does not.

| value | names | why it stays | removed by |
|---|---|---|---|
| `'users.manage'` | `USERS` (Users) · `REQUIRED` (tests/Http) | `AuthMiddlewareDenialTest` deliberately spells the value it asserts the seam receives | 2.6 |
| `'griffe'` | `BUNDLE` · `THEME` | two tests asserting two different things about one theme | 2.6 |
| `'Cardboard'` | `ADMIN_MODULE` · `MODULE` | two tests, two roles | 2.6 |
| `'203.0.113.7'` | `CLIENT_IP` · `REMOTE` | one RFC 5737 address written by two tests | 2.6 |
| `'#94a3b8'` | `DIFF_CONTEXT_INK` · `VERSION_INK_MUTED` | one colour, two roles in one palette | 2.6 |

The list also fails **when an entry stops applying** — an exemption that
stopped being a collision is rot in the ledger, not a free pass.

## Rule 2 — a value spelled as a literal outside its owner · ratchet at 532

A **ceiling, not a list.** 54 owned string values appear as raw literals on
**532 places** across the tree. An absolute rule here would need 532
exemptions, and a gate with 532 exemptions is not a gate:

| filter | values | places | files |
|---|---|---|---|
| none | 54 | 532 | 200 |
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
number, and the gate refuses the 534th.

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

## Rule 3 — the declaration count does not rise · ratchet at 241 / 203

This is the rule that catches the cheapest wrong fix: *"there are two names for
this, let me add a third."* That is the complaint this whole ledger started
from.

The gate excludes **its own file** from this count. It holds the ceilings, so
counting its own constants would mean the gate's next edit — including its own
approval list — moves the number it is meant to hold. Rules 1 and 2 still read
it.

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