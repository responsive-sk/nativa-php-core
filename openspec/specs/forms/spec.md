# forms Specification

## Purpose
A page or an article can name a form in its front matter and have it rendered
at the end of the body. This capability owns that form: its definition per
locale, the admin surface that edits it, the public endpoint that accepts a
submission, and the inbox that reads submissions. It exists so neither `Pages`
nor `Blog` has to learn what a field, a choice or a submission is, and so
`{{form:<slug>}}` is a placeholder with an owner rather than a raw string on a
published page.

## Requirements

### Requirement: A form definition is owned per locale

The system SHALL store each form definition under a unique `(slug, locale)`
pair, with a title, an intro, an optional success message and an ordered field
list. A second definition with the same slug in the same locale SHALL be
refused, and the same slug in another locale SHALL be a separate definition.

#### Scenario: The same slug in two locales

- GIVEN a published form `contact` in `sk_SK`
- WHEN an editor creates `contact` in `en_US`
- THEN both definitions exist and neither overwrites the other
- PINS: modules/Forms/tests/FormsCrudTest.php::theSameSlugExistsOncePerLocale

#### Scenario: A duplicate slug is refused

- GIVEN `contact` already exists in `sk_SK`
- WHEN a create request posts `contact` for `sk_SK`
- THEN the response is 400 with a slug error
- AND no second row is written
- PINS: modules/Forms/tests/FormsCrudTest.php::aDuplicateSlugIsRefused

#### Scenario: A fresh database carries the contact form

- GIVEN the forms database is created
- WHEN the migrator has run
- THEN `contact` exists in `sk_SK` and is published
- AND its field list is a valid schema
- PINS: modules/Forms/tests/FormsSeedTest.php::theDeploymentContactFormIsSeeded

#### Scenario: An edited definition is not overwritten

- GIVEN an editor renamed the seeded `contact` form
- WHEN the migrator runs again
- THEN the edited row is unchanged
- PINS: modules/Forms/tests/FormsSeedTest.php::anEditedDefinitionIsNotOverwritten

### Requirement: The admin surface answers at `/mark/forms`

`GET /mark/forms` SHALL list the definitions for the requested locale, and
`GET|POST /mark/forms/create` and `/mark/forms/{slug}/edit` SHALL round-trip a
definition through the same normalisation used for storage. Every admin
mutation SHALL be audited and SHALL flush the page cache, because the form's
markup is embedded in cached HTML.

#### Scenario: The list is served

- GIVEN the Forms module is installed and the session may manage content
- WHEN `GET /mark/forms` runs
- THEN the response is 200 and names the locale's definitions
- PINS: modules/Forms/tests/FormsCrudTest.php::theIndexIsServed

#### Scenario: Create and edit round-trip

- GIVEN an editor submits a definition with two fields
- WHEN the form is created and then edited to add a third field
- THEN the stored schema carries three fields in the submitted order
- AND the audit log holds one row per mutation
- PINS: modules/Forms/tests/FormsCrudTest.php::aDefinitionRoundTripsThroughCreateAndEdit

#### Scenario: A definition edit flushes cached markup

- GIVEN a published page whose body embeds the form and is in the page cache
- WHEN the definition's title changes
- THEN the cache entry is gone in the same request
- PINS: modules/Forms/tests/FormsCrudTest.php::aDefinitionEditFlushesTheCachedPage

### Requirement: An embedded form renders through the markdown block

`{{form:<slug>}}` SHALL expand to markup whose classes come from the active
theme's prefix, matching the selector set in the theme's `form.css`. A draft,
an unknown slug, a malformed schema or an unsafe placeholder SHALL render
nothing, and no other placeholder syntax SHALL be touched by this block.

#### Scenario: A published form on a page

- GIVEN a published `sk_SK` form `contact` and a published page whose body
  carries `{{form:contact}}`
- WHEN the page renders under the `jednotka` theme
- THEN the body contains `je-form` markup for every field
- AND every emitted class exists in `Frontend/packages/starter/src/form.css`
- PINS: modules/Forms/tests/FormBlockTest.php::aPublishedFormRendersWithTheThemePrefix

#### Scenario: Nothing to render

- GIVEN a draft form, an unknown slug and a schema that cannot be parsed
- WHEN each placeholder is expanded
- THEN every one of them is replaced by an empty string
- AND no `{{form:` remains in the rendered body
- PINS: modules/Forms/tests/FormBlockTest.php::nothingRendersForADraftUnknownOrBrokenForm

#### Scenario: Another block's placeholder is untouched

- GIVEN the body carries `{{page:contact}}` beside `{{form:contact}}`
- WHEN the body is rendered
- THEN `{{page:contact}}` is byte-identical in the output
- PINS: modules/Forms/tests/FormBlockTest.php::anotherPlaceholderIsLeftAlone

#### Scenario: Results are tallied over a bounded window

- GIVEN a choice field with `show_results` and more than 500 submissions
- WHEN the block renders after a success
- THEN each bar is computed from the newest 500 submissions
- AND the total shown is the number that was divided by
- PINS: modules/Forms/tests/FormBlockTest.php::resultBarsCountTheNewestFiveHundredSubmissions

### Requirement: A page names its form in the editor

The page create and edit surface SHALL accept an optional `form` slug, SHALL
store it in front matter only when it matches `^[a-z0-9][a-z0-9_-]{0,79}$`,
SHALL remove the key when the value is empty, and SHALL leave an existing value
untouched when an edit does not submit the field. `Pages` SHALL NOT resolve the
slug against `Forms`: an unknown or unpublished form is the block's decision at
render time, and the field stays a string.

#### Scenario: The slug round-trips through the editor

- GIVEN an editor creates a page and enters `contact` in the form field
- WHEN the page is saved and reopened for editing
- THEN the field holds `contact`
- AND the page's front matter carries `form: contact`
- PINS: modules/Pages/tests/PagesFormFieldTest.php::theSlugRoundTripsThroughTheEditor

#### Scenario: An invalid or empty slug

- GIVEN a page whose front matter carries `form: contact`
- WHEN the edit submits `Contact us!` and then an empty value
- THEN the first save is refused with a field error and keeps `contact`
- AND the second save removes the key from front matter
- PINS: modules/Pages/tests/PagesFormFieldTest.php::anInvalidSlugIsRefusedAndAnEmptyOneRemovesTheKey

#### Scenario: An unrelated edit preserves the field

- GIVEN a page carrying `form: contact`
- WHEN an edit changes only the title and does not submit a form value
- THEN the front matter still carries `form: contact`
- PINS: modules/Pages/tests/PagesFormFieldTest.php::anEditWithoutTheFieldKeepsIt

### Requirement: A submission validates against the stored schema

The public endpoint SHALL accept only the fields the definition declares,
SHALL apply the declared `required`, `email`, `max` and option rules, SHALL
drop keys the schema does not name, and SHALL store the values raw so a second
escape cannot double-encode a reader's text.

#### Scenario: A valid submission is stored

- GIVEN a published form with a required e-mail field
- WHEN the endpoint receives that field with a valid address
- THEN exactly one `new` submission row is written
- AND the payload holds only the declared keys
- PINS: modules/Forms/tests/FormSubmitActionTest.php::aValidSubmissionIsStoredOnce

#### Scenario: Invalid and undeclared values

- GIVEN a published form with a required field and a choice field
- WHEN the request omits the required field, sends an option outside the
  declared list and sends an undeclared key
- THEN the response is a re-render carrying the declared errors
- AND nothing is written
- PINS: modules/Forms/tests/FormSubmitActionTest.php::invalidAndUndeclaredValuesAreRefused

#### Scenario: A required consent box

- GIVEN a published form with a required `checkbox` field
- WHEN the request omits that key
- THEN the field is reported invalid and no row is written
- AND the same request with `1` is accepted
- PINS: modules/Forms/tests/FormSubmitActionTest.php::aRequiredConsentBoxIsEnforced

### Requirement: A submission is throttled and bot-guarded

Every submission SHALL pass the global CSRF stage, SHALL be discarded silently
when the honeypot is filled, and SHALL be denied before any write when the
per-IP, per-form window is exhausted. A refusal SHALL carry no `Location`
header.

#### Scenario: A filled honeypot is discarded

- GIVEN the honeypot field carries a value
- WHEN the endpoint runs
- THEN the response redirects to the return target
- AND no submission row exists
- PINS: modules/Forms/tests/FormSubmitActionTest.php::aFilledHoneypotWritesNothing

#### Scenario: The window is exhausted

- GIVEN five submissions from one address inside the window
- WHEN the sixth arrives
- THEN the response is 429
- AND it carries no `Location` header
- AND no sixth row exists
- PINS: modules/Forms/tests/FormSubmitActionTest.php::theSixthSubmissionIsRefusedWithoutARedirect

### Requirement: A submission returns to its embedding subject

On success the endpoint SHALL redirect to the embedding subject with
`form-sent=1` and an anchor for the form. On validation failure it SHALL
re-render that subject with the errors, the invalid field keys and the old
input, provided the return path resolves to published content. A return path
that is not a published page or article under the same site SHALL fall back to
the locale home on success, and SHALL answer 400 with no `Location` header when
there is also nothing to re-render.

#### Scenario: Success lands on the form

- GIVEN a valid submission from a page carrying the form
- WHEN the endpoint finishes
- THEN the redirect carries `form-sent=1#form-<slug>`
- AND the served page paints the confirmation
- PINS: modules/Forms/tests/FormSubmitActionTest.php::successRedirectsToTheEmbeddingForm

#### Scenario: Failure keeps the reader's words

- GIVEN a submission that fails validation
- WHEN the endpoint finishes
- THEN the subject page is re-rendered with the messages
- AND every declared field is marked invalid and carries its old value
- PINS: modules/Forms/tests/FormSubmitActionTest.php::anInvalidSubmissionKeepsTheOldInput

#### Scenario: Failure with nowhere to return

- GIVEN a submission that fails validation and a `form_return` naming a draft
- WHEN the endpoint finishes
- THEN the response is 400
- AND it carries no `Location` header and stores nothing
- PINS: modules/Forms/tests/FormSubmitActionTest.php::anInvalidSubmissionWithoutATargetAnswers400

#### Scenario: A foreign return path

- GIVEN `form_return` names another host, a traversal path or a draft page
- WHEN the endpoint succeeds
- THEN the redirect goes to the locale home
- PINS: modules/Forms/tests/FormSubmitActionTest.php::anUnsafeReturnPathFallsBackToTheLocaleHome

### Requirement: The inbox and the badge track new submissions

`GET /mark/forms/{slug}/submissions` SHALL list a form's submissions newest
first. Marking one handled SHALL move it out of the queue, deleting it SHALL
remove it, and the nav badge SHALL count `new` submissions only and SHALL
answer 0 when the database is absent.

#### Scenario: Handling clears the queue

- GIVEN two `new` submissions on one form
- WHEN one is marked handled
- THEN the inbox shows one `new` and one `handled`
- AND the badge counts one
- PINS: modules/Forms/tests/FormsBadgeTest.php::handlingOneSubmissionClearsOneFromTheBadge

#### Scenario: No database yet

- GIVEN the forms database does not exist
- WHEN the badge is asked for a count
- THEN it answers 0 instead of throwing
- PINS: modules/Forms/tests/FormsBadgeTest.php::aMissingDatabaseAnswersZero
