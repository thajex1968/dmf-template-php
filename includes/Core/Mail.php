<?php

/**
 * includes/Core/Mail.php
 * ==========================================================================
 * Object-oriented SMTP mailer (STARTTLS), dependency-free.
 *
 * A fluent wrapper over a raw-socket SMTP client — no PHPMailer, no Composer
 * package. Configured from an array (typically the MAIL_* values in Config) and
 * built up fluently, then sent:
 *
 *     $ok = $mail->to('a@b.co', 'Ann')
 *                ->subject('Welcome')
 *                ->html('<p>Hello</p>')
 *                ->send();
 *     if (!$ok) { $logger->error($mail->lastError()); }
 *
 * This is the OOP counterpart to the procedural includes/mailer.php; it does
 * not modify or depend on it.
 * ==========================================================================
 */

declare(strict_types=1);

namespace DMF\Core;

final class Mail
{
    private string $fromEmail;
    private string $fromName;

    /** @var array{email:string,name:string}|null */
    private ?array $recipient = null;

    private string $subject = '';
    private string $html = '';
    private ?string $text = null;
    private ?string $lastError = null;

    /**
     * @param array<string,mixed> $config host, port, username, password,
     *                                     encryption, from, from_name, timeout
     */
    public function __construct(
        private array $config,
        private ?Logger $logger = null,
    ) {
        $this->fromEmail = (string) ($config['from'] ?? '');
        $this->fromName = (string) ($config['from_name'] ?? $this->fromEmail);
    }

    // ── Fluent builders ───────────────────────────────────────────────────

    public function to(string $email, string $name = ''): self
    {
        $this->recipient = ['email' => $email, 'name' => $name !== '' ? $name : $email];
        return $this;
    }

    public function from(string $email, string $name = ''): self
    {
        $this->fromEmail = $email;
        $this->fromName = $name !== '' ? $name : $email;
        return $this;
    }

    public function subject(string $subject): self
    {
        $this->subject = $subject;
        return $this;
    }

    public function html(string $body): self
    {
        $this->html = $body;
        return $this;
    }

    public function text(string $body): self
    {
        $this->text = $body;
        return $this;
    }

    public function lastError(): ?string
    {
        return $this->lastError;
    }

    // ── Send ──────────────────────────────────────────────────────────────

    /**
     * Deliver the message. Returns true on success, false on failure (inspect
     * lastError()).
     */
    public function send(): bool
    {
        $this->lastError = null;

        if ($this->recipient === null) {
            return $this->fail('No recipient set.');
        }
        if ($this->fromEmail === '') {
            return $this->fail('No sender configured (MAIL_FROM).');
        }

        $host = (string) ($this->config['host'] ?? '');
        $port = (int) ($this->config['port'] ?? 587);
        $user = (string) ($this->config['username'] ?? '');
        $pass = (string) ($this->config['password'] ?? '');
        $encryption = strtolower((string) ($this->config['encryption'] ?? 'tls'));
        $timeout = (int) ($this->config['timeout'] ?? 15);

        if ($host === '') {
            return $this->fail('No SMTP host configured (MAIL_HOST).');
        }

        $errno = 0;
        $errstr = '';
        $socket = @fsockopen($host, $port, $errno, $errstr, $timeout);
        if (!$socket) {
            return $this->fail("SMTP connection failed: {$errstr} ({$errno})");
        }
        stream_set_timeout($socket, $timeout);

        try {
            $this->expect($socket, '220', 'greeting');
            $this->command($socket, 'EHLO ' . $this->clientHost(), '250', 'EHLO');

            if ($encryption === 'tls') {
                $this->command($socket, 'STARTTLS', '220', 'STARTTLS');
                if (!stream_socket_enable_crypto($socket, true, \STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    return $this->fail('TLS handshake failed.', $socket);
                }
                $this->command($socket, 'EHLO ' . $this->clientHost(), '250', 'EHLO(TLS)');
            }

            if ($user !== '') {
                $this->command($socket, 'AUTH LOGIN', '334', 'AUTH');
                $this->command($socket, base64_encode($user), '334', 'AUTH user');
                $this->command($socket, base64_encode($pass), '235', 'AUTH pass');
            }

            $this->command($socket, "MAIL FROM:<{$this->fromEmail}>", '250', 'MAIL FROM');
            $this->command($socket, "RCPT TO:<{$this->recipient['email']}>", '250', 'RCPT TO');
            $this->command($socket, 'DATA', '354', 'DATA');
            $this->command($socket, $this->buildMessage(), '250', 'message body');
            $this->write($socket, 'QUIT');
        } catch (\RuntimeException $e) {
            return $this->fail($e->getMessage(), $socket);
        }

        fclose($socket);
        return true;
    }

    // ── SMTP internals ────────────────────────────────────────────────────

    /**
     * @param resource $socket
     */
    private function command($socket, string $line, string $expected, string $stage): void
    {
        $this->write($socket, $line);
        $this->expect($socket, $expected, $stage);
    }

    /**
     * @param resource $socket
     */
    private function write($socket, string $line): void
    {
        fwrite($socket, $line . "\r\n");
    }

    /**
     * @param resource $socket
     */
    private function expect($socket, string $code, string $stage): void
    {
        $response = $this->read($socket);
        if (substr($response, 0, 3) !== $code) {
            throw new \RuntimeException("SMTP {$stage} failed: " . trim($response));
        }
    }

    /**
     * @param resource $socket
     */
    private function read($socket): string
    {
        $data = '';
        while (($line = fgets($socket, 512)) !== false) {
            $data .= $line;
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }
        return $data;
    }

    private function buildMessage(): string
    {
        $boundary = 'b_' . md5(uniqid('', true));
        $recipient = $this->recipient ?? ['email' => '', 'name' => ''];

        $headers = 'From: =?UTF-8?B?' . base64_encode($this->fromName) . "?= <{$this->fromEmail}>\r\n"
            . 'To: =?UTF-8?B?' . base64_encode($recipient['name']) . "?= <{$recipient['email']}>\r\n"
            . 'Subject: =?UTF-8?B?' . base64_encode($this->subject) . "?=\r\n"
            . "MIME-Version: 1.0\r\n"
            . "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n"
            . 'Date: ' . date('r') . "\r\n";

        $text = $this->text ?? strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $this->html));

        return $headers . "\r\n"
            . "--{$boundary}\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($text)) . "\r\n"
            . "--{$boundary}\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($this->html)) . "\r\n"
            . "--{$boundary}--\r\n"
            . '.';
    }

    private function clientHost(): string
    {
        $host = gethostname();
        return $host !== false ? $host : 'localhost';
    }

    /**
     * @param resource|null $socket
     */
    private function fail(string $message, $socket = null): bool
    {
        $this->lastError = $message;
        $this->logger?->error('Mail send failed: {message}', ['message' => $message]);
        if (is_resource($socket)) {
            @fclose($socket);
        }
        return false;
    }
}
