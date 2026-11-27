<?php

declare(strict_types = 1);

namespace Modules\Users\Infrastructure\Mail;

use Modules\Users\Domain\Contracts\Mailer;

/**
 * SendmailMailer — delivery through the local MTA a server actually has.
 *
 * The binary is invoked with an argument array, so the path, the sender and
 * the recipient never pass through a shell. The message goes to stdin, which
 * is what `sendmail -t` expects. Exit zero is acceptance by the local MTA; it
 * is not a promise that a human inbox received the message.
 *
 * Failure is a logged `false`, never an exception: a mailer is typically the
 * last step of a request or job that has already stored its row. The body is
 * never written to a log on failure, and an address containing CR or LF is
 * refused before the pipe runs, because a second header is an injection.
 */
final readonly class SendmailMailer implements Mailer
{
    /**
     * @param \Closure(list<string>, string): array{exit: int, stderr?: string}|null $pipe
     *        the process call, injectable so the suite asserts the argv and the
     *        message without executing a real MTA; `null` means the proc_open
     *        implementation below
     */
    public function __construct(
        private string $binary,
        private string $from,
        private ?\Closure $pipe = null,
    ) {
    }

    public function send(string $to, string $subject, string $text): bool
    {
        $binary = trim($this->binary);
        $from = trim($this->from);

        if ('' === $binary) {
            $this->logFailure($subject, $to, 'no sendmail binary is configured');

            return false;
        }

        if ('' === $from || self::hasLineBreak($from)) {
            $this->logFailure($subject, $to, 'the sender address is empty or contains a line break');

            return false;
        }

        if ('' === $to || self::hasLineBreak($to)) {
            $this->logFailure($subject, $to, 'the recipient address is empty or contains a line break');

            return false;
        }

        // A subject can carry an event title. A line break there is a second
        // header, so it becomes one space instead of being passed through.
        $subject = trim((string) preg_replace('/\s+/', ' ', str_replace(["\r", "\n"], ' ', $subject)));

        $message = $this->message($from, $to, $subject, $text);
        $pipe = $this->pipe ?? self::processPipe();

        try {
            $result = $pipe([$binary, '-t', '-i', '-f', $from], $message);
        } catch (\Throwable $exception) {
            $this->logFailure($subject, $to, 'the pipe failed: ' . $exception->getMessage());

            return false;
        }

        $exit = $result['exit'] ?? -1;

        if (0 !== $exit) {
            $stderr = trim((string) ($result['stderr'] ?? ''));
            $suffix = '' === $stderr ? '' : ' (' . substr($stderr, 0, 200) . ')';
            $this->logFailure($subject, $to, sprintf('%s exited %d%s', $binary, $exit, $suffix));

            return false;
        }

        return true;
    }

    private function message(string $from, string $to, string $subject, string $text): string
    {
        $header = [
            'From: ' . $from,
            'To: ' . $to,
            'Subject: ' . mb_encode_mimeheader($subject, 'UTF-8', 'B', "\r\n"),
            'Date: ' . date(DATE_RFC2822),
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
        ];

        return implode("\r\n", $header) . "\r\n\r\n" . $text;
    }

    private function logFailure(string $subject, string $to, string $reason): void
    {
        // The address may be the thing that was refused. One line per
        // rejection, so a CR or LF in a hostile recipient cannot forge a second
        // log entry.
        error_log(sprintf(
            '[mailer:sendmail] "%s" to %s was not sent: %s',
            $subject,
            str_replace(["\r", "\n"], ' ', $to),
            $reason,
        ));
    }

    private static function hasLineBreak(string $value): bool
    {
        return str_contains($value, "\r") || str_contains($value, "\n");
    }

    /**
     * @return \Closure(list<string>, string): array{exit: int, stderr: string}
     */
    private static function processPipe(): \Closure
    {
        return static function (array $command, string $message): array {
            $descriptors = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];

            $process = @proc_open($command, $descriptors, $pipes);

            if (!is_resource($process)) {
                return ['exit' => -1, 'stderr' => 'proc_open failed'];
            }

            $written = fwrite($pipes[0], $message);
            fflush($pipes[0]);
            fclose($pipes[0]);

            stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2], 4096);
            fclose($pipes[1]);
            fclose($pipes[2]);

            $exit = proc_close($process);

            if (false === $written) {
                return ['exit' => -1, 'stderr' => 'the message could not be written to the process'];
            }

            return ['exit' => $exit, 'stderr' => is_string($stderr) ? $stderr : ''];
        };
    }
}
