# reservations Specification

## Purpose
An order form already collects the customer, the date and the place through
the Forms module. This capability keeps the resulting order as a business
record with its own lifecycle, so an organizer can confirm or cancel it without
editing the submission queue or reading the form payload again. A submission
is an order when it carries a date and a place; every other submission belongs
only to Forms.

## Requirements

### Requirement: An order form becomes one reservation

The system SHALL create one reservation when a stored form submission carries
a non-empty `event_date`, `event_place`, `name` and `email`. The reservation's
service SHALL be the `service` answer when present, otherwise the form title.
A submission without a date and a place SHALL create no reservation.
Processing the same stored submission again SHALL NOT create a second order.

#### Scenario: A complete order form creates one order

- GIVEN a published form asking for a name, an e-mail, a service, a date and a place
- WHEN a customer submits all five answers
- THEN the submission is stored and one reservation carries the service, the
  date, the place and the customer
- AND the reservation starts in the `new` state
- PINS: modules/Reservations/tests/ReservationSubmissionTest.php::anOrderFormCreatesOneReservation

#### Scenario: A form without a date and place creates nothing

- GIVEN a published form asking only for a name and an e-mail
- WHEN a customer submits it
- THEN the submission is stored and the reservations database stays empty
- PINS: modules/Reservations/tests/ReservationSubmissionTest.php::aFormWithoutDateAndPlaceCreatesNothing

#### Scenario: A form without a service uses its title

- GIVEN a published order form with no `service` field
- WHEN a customer submits a date, a place, a name and an e-mail
- THEN one reservation carries the form title as its service
- AND the reservation starts in the `new` state
- PINS: modules/Reservations/tests/ReservationSubmissionTest.php::anOrderFormWithoutServiceUsesTheFormTitle

#### Scenario: A repeated handler call is idempotent

- GIVEN one stored submission that already became an order
- WHEN the same submission is processed again
- THEN the existing order is returned and no second row is written
- PINS: modules/Reservations/tests/ReservationSubmissionTest.php::aRepeatedSubmissionDoesNotDuplicateTheOrder

### Requirement: An organizer decides an order

The system SHALL let a content manager confirm or cancel a new or confirmed
reservation. Repeating the same decision SHALL be a no-op, and a cancelled
reservation SHALL be terminal.

#### Scenario: Confirm and cancel change the state

- GIVEN a new reservation
- WHEN an organizer confirms it and then cancels it
- THEN each change reports `changed: true` and stores the new status
- AND repeating the confirmation reports `changed: false`
- PINS: modules/Reservations/tests/ReservationDecisionTest.php::confirmAndCancelChangeTheStatus

#### Scenario: Cancellation is terminal

- GIVEN a cancelled reservation
- WHEN an organizer tries to confirm it
- THEN the decision reports `changed: false` and the status stays cancelled
- PINS: modules/Reservations/tests/ReservationDecisionTest.php::aCancelledOrderCannotBeConfirmed
