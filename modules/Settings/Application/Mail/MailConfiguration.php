<?php

declare(strict_types = 1);

namespace Modules\Settings\Application\Mail;

use Modules\Core\Infrastructure\Env;

/**
 * MailConfiguration — one resolved answer to "how does a message leave here?".
 *
 * It is deliberately database-free: the caller supplies the stored settings
 * and this class reads the environment fallbacks. That makes the same rule
 * testable with an array, usable from a settings renderer, and free of the
 * question "which connection did this object capture?".
 *
 * ## Precedence
 *
 * 1. a non-empty stored setting;
 * 2. its environment variable;
 * 3. the built-in default.
 *
 * ## Auto
 *
 * `auto` is the behaviour this tree shipped before this screen existed: the
 * development log in a development-named environment, the configured HTTP
 * transport outside it, and no transport when neither applies. An explicit
 * stored transport wins over `auto`, which is what makes sendmail usable on a
 * server with no SMTP relay.
 */
final readonly class MailConfiguration
{
    public const string KEY_TRANSPORT = 'mail_transport';

    public const string KEY_FROM = 'mail_from';

    public const string KEY_SENDMAIL_PATH = 'mail_sendmail_path';

    public const string KEY_API_URL = 'mail_api_url';

    // The constant names a settings key, not the credential it stores.
    // @mago-expect lint:no-literal-password
    public const string KEY_API_KEY = 'mail_api_key'; // audit-ok: settings key name, not a credential

    public const string ENV_FROM = 'MAIL_FROM';

    public const string ENV_API_URL = 'MAIL_API_URL';

    // The constant names an environment variable, not the credential it holds.
    // @mago-expect lint:no-literal-password
    public const string ENV_API_KEY = 'MAIL_API_KEY'; // audit-ok: environment variable name, not a credential

    public const string ENV_SENDMAIL_PATH = 'MAIL_SENDMAIL_PATH';

    public const string DEFAULT_SENDMAIL_PATH = '/usr/sbin/sendmail';

    /**
     * Environments whose missing transport is covered by the dev log.
     *
     * A whitelist, not "everything that is not production": an unrecognised
     * name is an environment nobody classified, and an unclassified
     * environment must not be the one that writes mail bodies to disk.
     *
     * @var list<string>
     */
    private const array DEV_ENVIRONMENTS = ['development', 'local', 'testing', 'test', 'dev'];

    public function __construct(
        public MailTransport $configured,
        public MailTransport $resolved,
        public string $from,
        public string $apiUrl,
        #[\SensitiveParameter]
        public string $apiKey,
        public string $sendmailPath,
        public ?string $problem = null,
    ) {
    }

    /**
     * Resolve the stored settings plus the environment fallbacks.
     *
     * @param array<string, mixed> $stored
     */
    public static function fromStored(array $stored): self
    {
        $configured = MailTransport::fromStored($stored[self::KEY_TRANSPORT] ?? null);
        $from = self::value($stored, self::KEY_FROM, self::environment(self::ENV_FROM));
        $apiUrl = self::value($stored, self::KEY_API_URL, self::environment(self::ENV_API_URL));
        $apiKey = self::value($stored, self::KEY_API_KEY, self::environment(self::ENV_API_KEY));
        $sendmailPath = self::value(
            $stored,
            self::KEY_SENDMAIL_PATH,
            self::environment(self::ENV_SENDMAIL_PATH),
        );

        if ('' === $sendmailPath) {
            $sendmailPath = self::DEFAULT_SENDMAIL_PATH;
        }

        $logAllowed = self::logsInThisEnvironment();

        return match ($configured) {
            MailTransport::Sendmail => self::sendmail($configured, $from, $apiUrl, $apiKey, $sendmailPath),
            MailTransport::Http     => self::http($configured, $from, $apiUrl, $apiKey, $sendmailPath),
            MailTransport::Disabled => new self(
                $configured,
                MailTransport::Disabled,
                $from,
                $apiUrl,
                $apiKey,
                $sendmailPath,
            ),
            default => $logAllowed
                ? new self($configured, MailTransport::Log, $from, $apiUrl, $apiKey, $sendmailPath)
                : self::automaticHttp($configured, $from, $apiUrl, $apiKey, $sendmailPath),
        };
    }

    /**
     * The development whitelist, in one place for both mail factories.
     */
    public static function logsInThisEnvironment(): bool
    {
        return in_array(
            strtolower(trim(self::environment('APP_ENV', 'development'))),
            self::DEV_ENVIRONMENTS,
            true,
        );
    }

    private static function sendmail(
        MailTransport $configured,
        string $from,
        string $apiUrl,
        #[\SensitiveParameter]
        string $apiKey,
        string $sendmailPath,
    ): self {
        if ('' === $from) {
            return new self(
                $configured,
                MailTransport::Disabled,
                $from,
                $apiUrl,
                $apiKey,
                $sendmailPath,
                'Sendmail mail is missing MAIL_FROM; nothing will be delivered.',
            );
        }

        return new self($configured, MailTransport::Sendmail, $from, $apiUrl, $apiKey, $sendmailPath);
    }

    private static function http(
        MailTransport $configured,
        string $from,
        string $apiUrl,
        #[\SensitiveParameter]
        string $apiKey,
        string $sendmailPath,
    ): self {
        $missing = self::missingHttpKeys($from, $apiUrl);

        if ([] !== $missing) {
            return new self(
                $configured,
                MailTransport::Disabled,
                $from,
                $apiUrl,
                $apiKey,
                $sendmailPath,
                'HTTP mail is not configured: missing ' . implode(', ', $missing) . '; nothing will be delivered.',
            );
        }

        return new self($configured, MailTransport::Http, $from, $apiUrl, $apiKey, $sendmailPath);
    }

    private static function automaticHttp(
        MailTransport $configured,
        string $from,
        string $apiUrl,
        #[\SensitiveParameter]
        string $apiKey,
        string $sendmailPath,
    ): self {
        $missing = self::missingHttpKeys($from, $apiUrl);

        if ([] === $missing) {
            return new self($configured, MailTransport::Http, $from, $apiUrl, $apiKey, $sendmailPath);
        }

        // Both empty is the ordinary "this deployment sends no mail" state and
        // needs no warning. ONE empty is a misconfiguration that would fail on
        // the first notification, so it is named here instead.
        if (2 === count($missing)) {
            return new self($configured, MailTransport::Disabled, $from, $apiUrl, $apiKey, $sendmailPath);
        }

        return new self(
            $configured,
            MailTransport::Disabled,
            $from,
            $apiUrl,
            $apiKey,
            $sendmailPath,
            'Mail transport is not configured: missing ' . implode(', ', $missing)
            . '; nothing will be delivered (storage/logs/app.log is NOT used as a fallback).',
        );
    }

    /**
     * @return list<string>
     */
    private static function missingHttpKeys(string $from, string $apiUrl): array
    {
        $missing = [];

        if ('' === $apiUrl) {
            $missing[] = self::ENV_API_URL;
        }

        if ('' === $from) {
            $missing[] = self::ENV_FROM;
        }

        return $missing;
    }

    /**
     * @param array<string, mixed> $stored
     */
    private static function value(array $stored, string $key, string $fallback): string
    {
        $value = $stored[$key] ?? null;

        if (is_string($value) && '' !== trim($value)) {
            return trim($value);
        }

        return trim($fallback);
    }

    private static function environment(string $key, string $default = ''): string
    {
        return (string) (Env::get($key, $default) ?? $default);
    }
}
