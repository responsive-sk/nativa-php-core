# Mail delivery > one configuration

Every message this tree sends asks the same question first: how does a message
leave here? The answer is stored in Mark Settings > Mail and resolved by
`Modules\Settings\Application\Mail\MailConfiguration`. Events, Mark
notifications and password reset all read that one answer through
`Modules\Users\Infrastructure\Mail\MailerFactory`.

The panel offers four transports:

| stored value | what happens |
|---|---|
| `auto` | the behaviour before this screen existed |
| `sendmail` | a local MTA receives the message through its binary |
| `http` | the message is POSTed as JSON to an endpoint |
| `disabled` | nothing is delivered |

`auto` uses the development log in a development-named environment, the HTTP
transport when `MAIL_API_URL` and `MAIL_FROM` are set, and no transport when
neither applies. An explicit stored choice wins over `auto`, which is what makes
sendmail usable on a server without an SMTP relay.

## Precedence, and why it is one class

For every value the order is:

1. a non-empty stored setting;
2. its `MAIL_*` environment fallback;
3. the built-in default.

`MailConfiguration::fromStored()` takes the stored array and never opens a
database connection. `MailerFactory::configuration()` reads the settings once,
catches a settings outage and falls back to the environment, so a missing table
cannot turn a mail send into a 500. The class owns the five settings keys, the
four environment names, the default sendmail path and the development
whitelist; callers import them instead of spelling them again.

## Sendmail

`SendmailMailer` starts the configured binary with `proc_open` and an argument
array: the binary, `-t`, `-i`, `-f` and the sender. The message body goes to
standard input. There is no shell string, so a sender or subject cannot become
a command. A missing sender, a relative path, a non-executable path or a body
that contains a bare carriage return is refused before the process starts.

The default path is `/usr/sbin/sendmail`. The panel validates the value as an
absolute path to an executable file before it is stored.

## HTTP

`HttpMailer` POSTs a JSON body with `from`, `to`, `subject` and `text` to the
configured endpoint. The API key is optional for a same-host relay and travels
as `Authorization: Bearer <key>`. A half configured HTTP transport is reported
by name in `storage/logs/app.log`; it is not guessed at.

The stored API key is write-only in the panel: the field renders empty, a blank
field keeps the current key, and the separate checkbox removes it.

## Test mail

The Mail tab carries a test form with a recipient field, defaulting to the
operator email. It sends one message through the saved transport, not through
the unsaved values above it. A disabled or misconfigured transport answers
with a refusal, and the page names the active transport on success. The test
is audited as `settings.mail.test`, so a delivery check leaves a record.

## Password reset

Reset links need `APP_URL`, because the link must not be built from a Host
header a caller can forge. Without it the reset mailer is the null
implementation and the reason is logged. With it, the link and subject come
from `PasswordResetMessage`, so the action and the mail agree on the path.

## Tests

| behaviour | test |
|---|---|
| precedence, auto and the development whitelist | `modules/Users/tests/MailConfigurationTest.php` |
| sendmail arguments and refusals | `modules/Users/tests/SendmailMailerTest.php` |
| the factory chooses from the stored transport | `modules/Users/tests/MailerTest.php` |
| reset mail through the shared configuration | `modules/Users/tests/PasswordResetMailerTest.php` |
| the Mail tab renders and saves | `modules/Cardboard/tests/MailSettingsCardTest.php` and `MailSettingsSaveTest.php` |
| the test message uses the saved transport | `modules/Cardboard/tests/MailSettingsSaveTest.php` |
