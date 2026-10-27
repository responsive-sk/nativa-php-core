## ADDED Requirements

### Requirement: A notification is one row per recipient

The system SHALL store one notification row per recipient in
`storage/data/notifications.db`, carrying the recipient (`notifiable_id`), the
kind, the title, the body, the panel path the notification is about, and that
recipient's own `read_at`. A `notify.admin` job SHALL write a row for every
active account that can open the panel — the admin role, or a role carrying
`mark.access` — and for nobody else: an account without panel access and a
disabled account SHALL NOT be addressed. Two recipients therefore have two rows
for one event, so one reading it does not clear the other's badge.

The row SHALL be a snapshot of the event (wording, actor and panel path copied
at write time) and SHALL carry the root-relative panel path rather than an
absolute URL, so the panel builds the link when it renders.

#### Scenario: A comment reaches both panel users

- GIVEN an active admin-role account and an active account whose role carries `mark.access`
- WHEN a comment moderation job is handled
- THEN each has one unread notification
- AND the account without panel access has none
- AND a disabled panel account has none

#### Scenario: The row names what happened and where to act

- GIVEN a handled `comment` job for article `why-a-port`
- WHEN the recipient's inbox is read
- THEN the row carries kind `comment`, the moderation title, the article and author in its body, and `/mark/comments` as its path
- AND the body carries no absolute "Moderate:" link, because only the mail gets one

### Requirement: An inbox belongs to one user and one user only

Every read and every write SHALL be scoped to the signed-in user. The page SHALL
list only that user's rows, newest first; the unread count SHALL count only that
user's unread rows; marking one row read SHALL match on the row id AND the
notifiable, so an id arriving in a form changes nothing when it belongs to
somebody else; and marking everything read SHALL clear only the signed-in user's
inbox. Reading a row SHALL keep it in the inbox.

#### Scenario: Another user's inbox is not rendered

- GIVEN user A has one notification and user B has one
- WHEN A opens the page
- THEN A sees their own row and the other is not present

#### Scenario: A foreign id in the form changes nothing

- GIVEN user B has an unread row
- WHEN A posts B's row id to the mark-read route
- THEN B's row is still unread

#### Scenario: Marking all read does not sweep the team

- GIVEN A and B each have unread rows
- WHEN A marks everything read
- THEN A has no unread rows
- AND B still has theirs
- AND A's rows remain in the inbox, marked read

### Requirement: The inbox is reachable and counted from the panel

`GET /mark/notifications` SHALL be registered under the panel gate with
`mark.access`, and the admin sidebar SHALL offer a Notifications item whose
badge reports the signed-in user's unread count (capped at `99+` by the layout).
The badge SHALL be asked only for an item the sidebar already decided to render,
so a session without `mark.access` is never told how many rows it may not read.
A database that is missing or not yet migrated SHALL answer zero for the badge
and an explanatory empty state on the page — never an error page and never a
blanked navigation.

#### Scenario: The badge counts this user's unread rows

- GIVEN the signed-in user has two unread rows and another user has one
- WHEN any `/mark` page renders
- THEN the Notifications badge shows 2

#### Scenario: An unmigrated inbox is not an outage

- GIVEN `notifications.db` has no `notifications` table
- WHEN the sidebar renders and the inbox page is opened
- THEN the badge renders nothing and the page explains that the database has not been created
- AND no admin page fails

### Requirement: The row is written before the mail is attempted

The handler SHALL write the inbox rows before sending the notification mail, SHALL
continue to the mail when the inbox write fails, and SHALL keep the row when the
transport refuses the message. A kind this version does not know SHALL write no
row and send nothing.

#### Scenario: A failed transport still leaves the row

- GIVEN the mailer refuses the message
- WHEN the job is handled
- THEN the handler reports the delivery as refused
- AND the recipient's inbox still holds the notification

#### Scenario: An unwritable inbox does not cost the mail

- GIVEN the notifications table does not exist
- WHEN the job is handled
- THEN the mail is still sent
- AND the failure is logged, not raised

#### Scenario: An unknown kind is not guessed at

- GIVEN a payload with kind `telegram`
- WHEN the job is handled
- THEN no inbox row is written and no mail is sent
