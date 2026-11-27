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
        private int $timeoutSeconds = 10,
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
        $pipe = $this->pipe ?? self::processPipe(max(1, $this->timeoutSeconds));

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
    private static function processPipe(int $timeoutSeconds): \Closure
    {
        return static function (array $command, string $message) use ($timeoutSeconds): array {
            $descriptors = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];

            $process = @proc_open($command, $descriptors, $pipes);

            if (!is_resource($process)) {
                return ['exit' => -1, 'stderr' => 'proc_open failed'];
            }

            foreach ($pipes as $pipe) {
                stream_set_blocking($pipe, false);
            }

            $input = $message;
            $stderr = '';
            $timedOut = false;
            $exit = -1;
            $deadline = microtime(true) + $timeoutSeconds;

            while (true) {
                if (is_resource($pipes[0]) && '' !== $input) {
                    $written = @fwrite($pipes[0], $input);

                    if (false === $written) {
                        $stderr .= 'the message could not be written to the process';
                        $input = '';
                    } else {
                        $input = substr($input, $written);
                    }
                }

                if (is_resource($pipes[0]) && '' === $input) {
                    fclose($pipes[0]);
                    $pipes[0] = null;
                }

                $read = [];

                if (is_resource($pipes[1])) {
                    $read[] = $pipes[1];
                }

                if (is_resource($pipes[2])) {
                    $read[] = $pipes[2];
                }

                if ([] !== $read) {
                    $write = null;
                    $except = null;
                    $ready = @stream_select($read, $write, $except, 0, 100_000);

                    if (false !== $ready && $ready > 0) {
                        foreach ($read as $pipe) {
                            $chunk = @fread($pipe, 8192);

                            if (!is_string($chunk) || '' === $chunk) {
                                continue;
                            }

                            if ($pipe === $pipes[2]) {
                                $stderr .= $chunk;
                            }
                        }
                    }
                }

                $status = proc_get_status($process);

                if (!$status['running']) {
                    $exit = $status['exitcode'];
                    break;
                }

                if (microtime(true) >= $deadline) {
                    $timedOut = true;
                    proc_terminate($process, 15);
                    usleep(100_000);

                    if ((bool) proc_get_status($process)['running']) {
                        proc_terminate($process, 9);
                    }

                    break;
                }

                usleep(10_000);
            }

            foreach ([1, 2] as $index) {
                if (!is_resource($pipes[$index])) {
                    continue;
                }

                $chunk = stream_get_contents($pipes[$index]);

                if (2 === $index && is_string($chunk) && '' !== $chunk) {
                    $stderr .= $chunk;
                }

                fclose($pipes[$index]);
            }

            if (is_resource($pipes[0])) {
                fclose($pipes[0]);
            }

            $close = proc_close($process);

            if ($timedOut) {
                return [
                    'exit' => 124,
                    'stderr' => 'timed out after ' . $timeoutSeconds . ' second' . (1 === $timeoutSeconds ? '' : 's'),
                ];
            }

            if ($exit < 0) {
                $exit = $close;
            }

            return ['exit' => $exit, 'stderr' => $stderr];
        };
    }
}
