# validation Specification

Canonical spec for `modules/Validation/` (Marko adoption, Phase A).
Ported from Marko's `validation` package DSL; rule names match Marko,
semantics match except where DELIBERATE DEVIATION is noted. Keep this
file in sync when validation behavior changes — use the OpenSpec change
workflow (new change in `openspec/changes/`) rather than editing here
directly. Do not edit archived changes; they are history.

## Purpose

One string-DSL validator for every form and service in the tree, so
field rules live in one module and call-sites keep only their legacy
user-facing messages. Database-backed checks (email taken, locale
available) are NOT rules — they stay manual at the call-site, positioned
after the field errors (AuthService pattern).

Marko runs every rule and would double-report an empty password; this module
reports once. Call-sites that previously compared `=== ''` on raw input now also
reject whitespace-only values, a deviation documented at each such call-site.

## Requirements

### Requirement: String DSL

The system SHALL parse `'required|min:8|confirmed'` rule strings (plus
`RuleInterface` objects and lists mixing both) into rule objects via
`RuleParser`. A `regex:` token consumes the rest of the string, so it
MUST come last. Unknown rule names SHALL throw `InvalidArgumentException`
at parse time, never as a validation error.

#### Scenario: Mixed string and object rules

- GIVEN data `['nick' => 'ed']` and rules `['nick' => 'required|min:8']`
- WHEN `Validator::validate()` runs
- THEN the result contains exactly one error for `nick`

- PINS: modules/Validation/tests/ValidatorTest.php::stringRulesReportOneError

### Requirement: Rule catalog

The module SHALL provide `required`, `nullable`, `email`, `string`,
`integer`, `numeric`, `min`, `max`, `between`, `confirmed` (against the
`<field>_confirmation` key), `same`, `different`, `in`, `not_in`,
`array`, `boolean`, `date`, `url` and `regex` rules with Marko-compatible
names. `Min`/`Max` SHALL compare numerically only once the field is
declared `integer`/`numeric`; otherwise they measure string length
(`mb_strlen`), so `string|min:8` never accepts the password `"9"` by
value.

#### Scenario: Numeric mode is declared, not guessed

- GIVEN data `['a' => '9']` and rules `['a' => 'integer|between:1,5']`
- WHEN validated
- THEN the result is non-empty (9 is outside 1..5)

- PINS: modules/Validation/tests/ValidatorTest.php::numericModeComparesByValue

### Requirement: Skip semantics

`nullable` with an empty value SHALL skip every rule including `Required`. A
non-required empty value SHALL skip every rule. A FAILED `Required` SHALL stop
the field's remaining rules, so an empty `required|min:8` password reports ONE
error. `Required` SHALL reject null, `''`, whitespace-only strings and `[]`.

#### Scenario: Empty password reports once

- GIVEN data `['password' => '', 'password_confirmation' => '']`
- AND rules `['password' => 'required|min:8|confirmed']`
- WHEN validated
- THEN the error count is 1

- PINS: modules/Validation/tests/ValidatorTest.php::emptyPasswordReportsOnce

### Requirement: Custom messages

Message lookup SHALL resolve `concrete-field.rule`, then
`declared-key.rule` (for wildcards), then the rule's own default.
`Email` and `Regex` SHALL be lenient on empty (return true), so the
`required` message owns empty values.

#### Scenario: Legacy AuthService messages

- GIVEN the register rules with `name.required`, `email.email`,
  `password.min` and `password.confirmed` custom messages
- WHEN input `('', 'not-an-email', 'short', 'different')` is validated
- THEN the messages are exactly the four legacy strings in field order

- PINS: modules/Validation/tests/ValidatorTest.php::customMessagesUseFieldRuleKeys

### Requirement: Wildcards

`items.*.email` SHALL expand over the data present and key errors under
the concrete path (e.g. `items.0.email`). A `*` over an absent or empty
value SHALL expand to nothing; a required base the data cannot reach is
reported on the declared key, never passed silently.

#### Scenario: Concrete error paths

- GIVEN data `['items' => [['email' => 'bad'], ['email' => 'ok@example.test']]]`
- AND rules `['items.*.email' => 'required|email']`
- WHEN validated
- THEN one error exists under `items.0.email` and none under `items.1.email`

- PINS: modules/Validation/tests/ValidatorTest.php::wildcardsErrorUnderConcretePaths

### Requirement: Error shape

`ValidationErrors` SHALL key messages by concrete field path and expose
`all()` (every message in field-then-rule order — the shape form
renderers and `AuthService::register()` return), `forField()`,
`isEmpty()`/`isNotEmpty()` and `count()`. `validateOrFail()` SHALL
throw `ValidationException` carrying the errors when non-empty.

#### Scenario: Errors flatten in field-then-rule order

- GIVEN data `['name' => '', 'email' => 'nope']`
- AND rules `['name' => 'required', 'email' => 'required|email']`
- WHEN `Validator::validate()` runs
- THEN `count()` is 2 and `forField('name')` holds the required message
- AND `all()` lists the `name` message before the `email` message

- PINS: modules/Validation/tests/ValidatorTest.php::errorsFlattenInFieldThenRuleOrder

#### Scenario: validateOrFail carries the errors

- GIVEN data `['email' => 'nope']` and rules `['email' => 'required|email']`
- WHEN `validateOrFail()` runs
- THEN it throws `ValidationException`
- AND `errors()->forField('email')` holds exactly one message

- PINS: modules/Validation/tests/ValidatorTest.php::validateOrFailThrowsWithErrors

### Requirement: Strict boolean

`boolean` SHALL accept only `true`/`false`, `1`/`0`, `'1'`/`'0'`.

DELIBERATE DEVIATION: filter-style leniency (`yes`/`no`/`on`/`off`)
is rejected — form encodings only.

#### Scenario: Form encodings are accepted

- GIVEN data `['active' => '1']` and rules `['active' => 'boolean']`
- WHEN validated
- THEN the result is empty

- PINS: modules/Validation/tests/ValidatorTest.php::scalarRules

#### Scenario: Filter-style words are rejected

- GIVEN data `['active' => 'yes']` and rules `['active' => 'boolean']`
- WHEN validated
- THEN the result contains one error for `active`

- PINS: modules/Validation/tests/ValidatorTest.php::scalarRules
