# health Specification

## Purpose

TBD - created by archiving change marko-adoption. Update Purpose after archive.

## Requirements

### Requirement: Health endpoint contract

The system SHALL expose `GET|HEAD /health` returning `application/json` with a
body of `{"status": <healthy|degraded|unhealthy>, "checks": [...]}`, where every
entry carries `name`, `status`, `message` and `duration_ms`. The response SHALL
carry `Cache-Control: no-store`. Only `unhealthy` SHALL answer 503; `healthy`
and `degraded` SHALL answer 200 so an unfinished tree is not an outage. The
payload SHALL NOT serialize the exception behind a failed probe, so no path,
driver message or DSN reaches an anonymous caller.

#### Scenario: A healthy tree answers 200

- GIVEN every registered probe reports `healthy`
- WHEN `GET /health` runs
- THEN the response is 200 with body status `healthy`
- AND `checks` lists one entry per probe, each carrying `duration_ms`
- AND the response carries `Cache-Control: no-store`

- PINS: modules/Health/tests/HealthActionTest.php::everyCheckHealthyAnswersTwoHundred
- PINS: modules/Health/tests/HealthActionTest.php::theResponseCannotBeCachedByAnythingInFrontOfIt

#### Scenario: One unhealthy probe answers 503

- GIVEN the database probe cannot answer `SELECT 1`
- WHEN `GET /health` runs
- THEN the response is 503 with body status `unhealthy`
- AND every probe still appears in `checks`
- AND the body carries no filesystem path or driver message

- PINS: modules/Health/tests/HealthActionTest.php::oneUnhealthyCheckAnswersFiveZeroThreeEvenWithHealthyOnesBeforeIt
- PINS: modules/Health/tests/HealthActionTest.php::thePayloadNeverCarriesTheException

#### Scenario: HEAD is routed as well as GET

- GIVEN the route is registered
- WHEN `HEAD /health` runs
- THEN the response status is 200

- PINS: modules/Health/tests/HealthActionTest.php::theRouteAnswersHeadAsWellAsGet

### Requirement: Ordered probes

The system SHALL run the registered probes in registration order and report them
in that order: module databases first, writable storage second, theme bundle
manifests third. Database probing SHALL cover the SQLite files that exist for
installed modules, and a `DB_<NAME>` override SHALL count only when its value
names an existing `.db` file, so another tool's `DB_HOST` cannot become a probe.

#### Scenario: Registration order is payload order

- GIVEN the default registry
- WHEN `/health` runs
- THEN `checks` names `database`, `filesystem` and `dist` in that order

- PINS: modules/Health/tests/HealthServicesTest.php::theRegisteredProbesAreNamedAndOrdered
- PINS: modules/Health/tests/HealthCheckRegistryTest.php::resultsKeepRegistrationOrder

#### Scenario: A non-SQLite override is ignored

- GIVEN `DB_FOREIGN` points at a file that does not end in `.db`
- WHEN the database probe set is resolved
- THEN `foreign` is not among the probed module databases

- PINS: modules/Health/tests/HealthServicesTest.php::anOverrideCountsOnlyWhenItNamesASqliteFile

### Requirement: Bootstrapping states are not outages

The system SHALL report `degraded` when there is nothing to probe — no module
database file, no storage directory configured, or no theme bundle built — and
SHALL report `healthy` for an empty registry, because "nothing registered" is
not "broken".

#### Scenario: No theme bundle answers 200 degraded

- GIVEN `public/dist` holds no bundle directory with an `assets/` child
- WHEN `/health` runs
- THEN the response is 200 with body status `degraded`

- PINS: modules/Health/tests/HealthActionTest.php::degradedIsTwoHundredAndSaysSoInTheBody
- PINS: modules/Health/tests/HealthChecksTest.php::noBundleAtAllIsDegraded

#### Scenario: An empty registry answers healthy

- GIVEN no probe is registered
- WHEN the action handles the request
- THEN the response is 200 with `{"status":"healthy","checks":[]}`

- PINS: modules/Health/tests/HealthActionTest.php::anEmptyRegistryAnswersHealthyRatherThanFailing
