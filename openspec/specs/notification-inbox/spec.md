# notification-inbox Specification

## Purpose

An inbox is a per-user record of what happened and where to act on it. One
event writes one row per recipient, so reading it clears one badge and not the
team's, and a missing or unmigrated database degrades the badge instead of the
panel.

## Requirements

### Requirement: A notification is one row per recipient

The system SHALL store one row per recipient in
`storage/data/notifications.db`, carrying `notifiable_id`, kind, title, body,
the panel path, and that recipient's own `read_at`. A `notify.admin` job SHALL
address every active account that can open the panel, the admin role or a role
carrying `mark.access`, and nobody else. Two recipients SHALL be two rows for
one event, so one reading it does not clear the other's badge. The row SHALL be
a snapshot carrying the root-relative panel path.

#### Scenario: A comment reaches both panel users

- GIVEN an active admin-role account and an active account whose role carries `mark.access`
- WHEN a comment moderation job is handled
- THEN each has one unread notification
- AND the account without panel access has none
- AND a disabled panel account has none
- PINS: modules/Users/tests/MarkInboxWiringTest.php::aCommentLandsInTheInboxOfEveryPanelUser

#### Scenario: The row names what happened and where to act

- GIVEN a handled `comment` job for article `why-a-port`
- WHEN the recipient's inbox is read
- THEN the row carries kind `comment`, the moderation title, the article and author in its body, and `/mark/comments` as its path
- AND the body carries no absolute "Moderate:" link, because only the mail gets one
- PINS: modules/Users/tests/MarkInboxWiringTest.php::theRowCarriesTheMailWordingAndTheProducersPanelPath

### Requirement: An inbox belongs to one user and one user only

Every read and every write SHALL be scoped to the signed-in user. The page
SHALL list only that user's rows, newest first; the unread count SHALL count
only that user's unread rows; marking one row read SHALL match on the row id
AND the notifiable, so an id arriving in a form changes nothing when it belongs
to somebody else; and marking everything read SHALL clear only that user's
inbox. Reading a row SHALL keep it in the inbox.

#### Scenario: Another user's inbox is not rendered

- GIVEN user A has one notification and user B has one
- WHEN A opens the page
- THEN A sees their own row and the other is not present
- PINS: modules/Cardboard/tests/NotificationsPageTest.php::itListsTheSignedInUsersInboxAndReachesItFromTheSidebar

#### Scenario: A foreign id in the form changes nothing

- GIVEN user B has an unread row
- WHEN A posts B's row id to the mark-read route
- THEN B's row is still unread
- PINS: modules/Cardboard/tests/NotificationsPageTest.php::anotherUsersRowCannotBeClearedFromTheForm

#### Scenario: Marking all read does not sweep the team

- GIVEN A and B each have unread rows
- WHEN A marks everything read
- THEN A has no unread rows
- AND B still has theirs
- AND A's rows remain in the inbox, marked read
- PINS: modules/Cardboard/tests/NotificationsPageTest.php::markAllAsReadClearsOnlyTheSignedInUsersInbox

### Requirement: The inbox is reachable and counted from the panel

`GET /mark/notifications` SHALL be registered under the panel gate with
`mark.access`, and the admin sidebar SHALL offer a Notifications item whose
badge reports the signed-in user's unread count, capped at `99+` by the layout
and asked only for an item the sidebar already decided to render. A database
that is missing or not yet migrated SHALL answer zero for the badge and an
explanatory empty state on the page, never an error page or a blanked
navigation.

#### Scenario: The badge counts this user's unread rows

- GIVEN the signed-in user has two unread rows and another user has one
- WHEN any `/mark` page renders
- THEN the Notifications badge shows 2
- PINS: modules/Notifications/tests/NotificationsBadgeTest.php::itCountsTheSignedInUsersUnreadRows

#### Scenario: An unmigrated inbox is not an outage

- GIVEN `notifications.db` has no `notifications` table
- WHEN the sidebar renders and the inbox page is opened
- THEN the badge renders nothing and the page explains that the database has not been created
- AND no admin page fails
- PINS: modules/Notifications/tests/NotificationsBadgeTest.php::aMissingTableAnswersZeroInsteadOfThrowing
- PINS: modules/Cardboard/tests/NotificationsPageTest.php::anUnmigratedInboxExplainsItself

### Requirement: The row is written before the mail is attempted

The handler SHALL write the inbox rows before sending the notification mail,
SHALL continue to the mail when the inbox write fails, and SHALL keep the row
when the transport refuses the message. A kind this version does not know SHALL
write no row and send nothing.

#### Scenario: A failed transport still leaves the row

- GIVEN the mailer refuses the message
- WHEN the job is handled
- THEN the handler reports the delivery as refused
- AND the recipient's inbox still holds the notification
- PINS: modules/Users/tests/MarkInboxWiringTest.php::aMailTheTransportRefusesStillLeavesItsRow

#### Scenario: An unwritable inbox does not cost the mail

- GIVEN the notifications table does not exist
- WHEN the job is handled
- THEN the mail is still sent
- AND the failure is logged, not raised
- PINS: modules/Users/tests/MarkInboxWiringTest.php::anInboxThatCannotBeWrittenDoesNotCostTheMail

#### Scenario: An unknown kind is not guessed at

- GIVEN a payload with kind `telegram`
- WHEN the job is handled
- THEN no inbox row is written and no mail is sent
- PINS: modules/Users/tests/MarkInboxWiringTest.php::anUnknownKindWritesNoRow
