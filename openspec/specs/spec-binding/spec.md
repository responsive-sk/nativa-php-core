# spec-binding Specification

## Purpose

A spec scenario that names no test is a claim nobody checks. This capability
binds every canonical scenario to the test that holds it, and proves the binding
by reading files as text, so the verdict is the same on a kernel-only checkout.

The binding stays a bullet the openspec validator ignores rather than a new file
format: measured 2026-10-27, a scenario carrying a `PINS:` bullet passes
`openspec validate --strict` with exit 0, so no forked grammar and no sidecar
file is introduced.

## Requirements

### Requirement: Every scenario names the test that holds it

Every `#### Scenario:` in a canonical spec under `openspec/specs/` and in a
change delta under `openspec/changes/*/specs/` SHALL carry at least one `PINS:`
bullet in its GIVEN/WHEN/THEN list, naming either a test reference of the form
`<repo-relative test file>::<method>` or `none — <reason>`. Change directories
under `openspec/changes/archive/` SHALL be excluded: an archived change is
history and is never edited again.

#### Scenario: A scenario with a resolvable pin passes

- GIVEN `openspec/specs/validation/spec.md` and its scenario `Empty password reports once`
- AND a `PINS:` bullet naming `modules/Validation/tests/ValidatorTest.php::emptyPasswordReportsOnce`
- WHEN the binding check runs
- THEN the scenario is accepted

- PINS: tests/Architecture/ScenarioTestBindingContractTest.php::everyScenarioNamesATest

#### Scenario: A scenario without a pin fails

- GIVEN a spec file under `openspec/specs/` whose scenario has GIVEN/WHEN/THEN and no `PINS:` line
- WHEN the binding check runs
- THEN it fails and names the spec file and the scenario

- PINS: tests/Architecture/ScenarioTestBindingContractTest.php::everyScenarioNamesATest

### Requirement: A pin resolves to a real file and a real method

The check SHALL read the pinned file as text and require (a) that it exists
under `tests/` or `modules/*/tests/`, and (b) that it contains
`function <method>(`. It SHALL NOT load the file or the module it belongs to,
so the result does not depend on which modules a checkout has installed. One
pin MAY hold several scenarios, and a pin naming a file outside the two test
roots SHALL be refused.

#### Scenario: A pin outside the test roots is refused

- GIVEN a scenario pinning `src/Modules/Core/Infrastructure/Http/Request.php::bodyString`
- WHEN the binding check runs
- THEN it fails, because the check reads tests and only tests

- PINS: tests/Architecture/ScenarioTestBindingContractTest.php::aPinResolvesToRealFileAndMethod

#### Scenario: The check passes on a kernel-only checkout

- GIVEN a checkout with no module installed under `src/Modules/`
- WHEN the binding check runs
- THEN it reaches the same verdict as a checkout with the full catalogue, because it reads files as text

- PINS: tests/Architecture/ScenarioTestBindingContractTest.php::aPinResolvesToRealFileAndMethod

### Requirement: An exemption is a counted decision, not a silence

`PINS: none` SHALL carry a reason after an em dash; an exemption without one
SHALL fail. The number of exemptions SHALL be held as a ratchet inside the
check: exceeding it SHALL fail, and lowering it SHALL be a code change in the
same review as the annotation that removed the exemption. A scenario added
without a pin SHALL fail regardless of the ratchet's headroom, so the ratchet
cannot hide anything new — it only grandfathers scenarios that already existed
when the check landed.

#### Scenario: An exemption without a reason fails

- GIVEN a scenario whose pin line reads `- PINS: none`
- WHEN the binding check runs
- THEN it fails and asks for the decision rather than reporting the absence

- PINS: tests/Architecture/ScenarioTestBindingContractTest.php::anExemptionCarriesAReasonAndIsCounted

#### Scenario: A new unpinned scenario fails while the ratchet still has headroom

- GIVEN a ratchet of 48 recorded exemptions
- AND a 49th scenario added to a change delta with no `PINS:` line
- WHEN the binding check runs
- THEN it fails on the new scenario, not on the count

- PINS: tests/Architecture/ScenarioTestBindingContractTest.php::anExemptionCarriesAReasonAndIsCounted

### Requirement: A missing spec tree fails instead of passing by silence

`openspec/specs/` is tracked, so a checkout without it is a moved or deleted
tree, not a legitimate CI state. The check SHALL assert the floor of files and
scenarios it read whenever it runs and SHALL fail when the walk finds zero,
rather than skip or pass. Only a tracked, present tree is a normal input.

#### Scenario: A checkout without `openspec/` fails on the floor

- GIVEN a checkout where `/openspec/` does not exist
- WHEN the binding check runs
- THEN it fails because the walk found 0 scenarios
- AND the message names the count and the moved root

- PINS: tests/Architecture/ScenarioTestBindingContractTest.php::everyScenarioNamesATest

### Requirement: The binding adds no gate to CI

The check SHALL run inside the existing PHPUnit job. It SHALL NOT add a CI job,
a Makefile target, a `mago.toml` scope or a `phpstan.neon` path: the tree
already publishes 15 CI jobs, and a binding that costs a job is a binding that
will be argued about instead of used.

#### Scenario: The CI job list is unchanged by this capability

- GIVEN the workflow before and after this change
- WHEN the job keys are counted
- THEN both counts are 15

- PINS: tests/Architecture/ScenarioTestBindingContractTest.php::theBindingNeedsNoNewGate
