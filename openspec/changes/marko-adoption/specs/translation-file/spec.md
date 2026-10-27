## ADDED Requirements

### Requirement: The locale file is a fallback source, not a second database

The system SHALL resolve a translation as: per-request cache → `translations`
row → `storage/locales/<locale>.json` → caller default → the raw key. A key the
database holds SHALL render from the database even when the file carries the
same key, so an operator edit is never shadowed by a file value. The file SHALL
be read and parsed at most once per request and per locale, because `get()` is
called once per key per rendered page.

#### Scenario: A row the database does not have comes from the file

- GIVEN `sk_SK.json` carries `nav.home` and the database has no row for it
- WHEN `nav.home` is resolved for `sk_SK`
- THEN the value from the file is returned
- AND the key does not render raw

#### Scenario: The database wins over the file

- GIVEN `nav.home` exists both as a row and in `sk_SK.json`
- WHEN it is resolved for `sk_SK`
- THEN the row's value is returned

#### Scenario: An unknown key falls through to the default and then to its key

- GIVEN neither source has `nav.missing`
- WHEN it is resolved with a default
- THEN the default is returned
- AND without a default the key itself is returned

### Requirement: The admin exports a locale's rows to its file

The system SHALL expose `POST /mark/locales/export` behind the admin session and
the CSRF middleware. It SHALL write the locale's `translations` rows to
`storage/locales/<locale>.json` as a key → value object and SHALL report how
many translations were written. A locale without rows SHALL write no file at
all, because an empty file would become the fallback that replaces a language
with nothing, and only the requested locale's file SHALL be touched. The export
SHALL NOT flush the page cache: it writes a file the fallback reads, and no
stored page changes.

#### Scenario: An export writes exactly that locale's rows

- GIVEN `sk_SK` has two rows and `en_US` has one
- WHEN the export runs for `sk_SK`
- THEN `sk_SK.json` holds exactly those two keys and no other
- AND `en_US.json` is not created
- AND the panel reports two translations exported

#### Scenario: A locale without rows exports nothing

- GIVEN `en_US` has no rows
- WHEN the export runs for `en_US`
- THEN no file is written
- AND the panel reports that there was nothing to export

#### Scenario: The export is audited

- GIVEN an authenticated admin posts the export form
- WHEN the export completes
- THEN an audit entry records the locale and the number of translations

### Requirement: The panel shows a key the file provides

The admin translation list SHALL show every key the file provides as well as the
database rows, each file-backed key marked with its `file` source. A file row
SHALL carry no id, no edit link and no delete button, because editing it in
place would silently create a database row that then shadows the file. A key
present in both sources SHALL appear once, as the database row. A file key SHALL
take its group from its key prefix, and the group and search filters SHALL apply
to file rows through the same predicates the repository applies to rows, so a
filter cannot show a file row the database half of the same query would have
hidden.

#### Scenario: A file-only key is visible and marked

- GIVEN `forms.tagline` exists only in `en_US.json`
- WHEN the list is rendered for `en_US`
- THEN the row appears with source `file`, group `forms` and an empty id
- AND the row offers no edit link

#### Scenario: A duplicated key is listed as its row

- GIVEN `nav.blog` exists as a row and in the file
- WHEN the list is rendered
- THEN it appears once, as the database row, and stays editable

### Requirement: A key the file provides is not reported as missing

The missing-key report SHALL exclude keys the locale's file renders, so a
file-backed locale does not report its own working keys as gaps the operator has
to fill a second time.

#### Scenario: A file-backed key is not a gap

- GIVEN `nav.blog` has an `en_US` row and `sk_SK.json` carries `nav.blog`
- WHEN missing `sk_SK` keys are reported
- THEN `nav.blog` is not listed
