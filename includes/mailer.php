<?php

/**
 * includes/mailer.php
 * ==========================================================================
 * Dependency-free SMTP client (STARTTLS, e.g. Gmail on port 587).
 *
 * Kept verbatim from the reference architecture as reusable infrastructure —
 * no PHPMailer / SwiftMailer, no Composer runtime dependency. Configuration
 * comes from the SMTP_* constants defined in config/config.php (which read
 * from .env).
 * ==========================================================================
 */

declare(strict_types=1);

/**
 * Send an HTML email via SMTP.
 *
 * @param string $toEmail  Recipient address.
 * @param string $toName   Recipient display name.
 * @param string $subject  Subject line.
 * @param string $htmlBody HTML body (a plain-text part is derived automatically).
 * @return bool|string     true on success, or an error message string on failure.
 */
function sendMail(string $toEmail, string $toName, string $subject, string $htmlBody): bool|string
{
    $host = SMTP_HOST;
    $port = SMTP_PORT;
    $user = SMTP_USER;
    $pass = SMTP_PASS;
    $from = SMTP_FROM;
    $name = SMTP_NAME;

    if ($pass === '') {
        return 'MAIL_PASSWORD is not configured in .env';
    }

    // ── Open socket ───────────────────────────────────────────────────────
    $errno = 0;
    $errstr = '';
    $sock = fsockopen($host, $port, $errno, $errstr, 10);
    if (!$sock) {
        return "SMTP connection failed: $errstr ($errno)";
    }
    stream_set_timeout($sock, 15);

    $read = static function () use ($sock): string {
        $r = '';
        while ($line = fgets($sock, 512)) {
            $r .= $line;
            if (substr($line, 3, 1) === ' ') {
                break;
            }
        }
        return $r;
    };
    $send = static function (string $cmd) use ($sock, $read): string {
        fwrite($sock, $cmd . "\r\n");
        return $read();
    };

    // ── Greeting ──────────────────────────────────────────────────────────
    $r = $read();
    if (substr($r, 0, 3) !== '220') {
        fclose($sock);
        return "SMTP greeting: $r";
    }

    // ── EHLO ──────────────────────────────────────────────────────────────
    $r = $send('EHLO ' . gethostname());
    if (substr($r, 0, 3) !== '250') {
        fclose($sock);
        return "EHLO: $r";
    }

    // ── STARTTLS ──────────────────────────────────────────────────────────
    if (strtolower(SMTP_ENCRYPTION) === 'tls') {
        $r = $send('STARTTLS');
        if (substr($r, 0, 3) !== '220') {
            fclose($sock);
            return "STARTTLS: $r";
        }
        $crypto = stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        if (!$crypto) {
            fclose($sock);
            return 'TLS handshake failed';
        }
        // EHLO again after the TLS upgrade.
        $r = $send('EHLO ' . gethostname());
        if (substr($r, 0, 3) !== '250') {
            fclose($sock);
            return "EHLO after TLS: $r";
        }
    }

    // ── AUTH LOGIN ────────────────────────────────────────────────────────
    $r = $send('AUTH LOGIN');
    if (substr($r, 0, 3) !== '334') {
        fclose($sock);
        return "AUTH LOGIN: $r";
    }
    $r = $send(base64_encode($user));
    if (substr($r, 0, 3) !== '334') {
        fclose($sock);
        return "AUTH username: $r";
    }
    $r = $send(base64_encode($pass));
    if (substr($r, 0, 3) !== '235') {
        fclose($sock);
        return "AUTH password rejected (check credentials): $r";
    }

    // ── Envelope ──────────────────────────────────────────────────────────
    $r = $send("MAIL FROM:<$from>");
    if (substr($r, 0, 3) !== '250') {
        fclose($sock);
        return "MAIL FROM: $r";
    }
    $r = $send("RCPT TO:<$toEmail>");
    if (substr($r, 0, 3) !== '250') {
        fclose($sock);
        return "RCPT TO: $r";
    }
    $r = $send('DATA');
    if (substr($r, 0, 3) !== '354') {
        fclose($sock);
        return "DATA: $r";
    }

    // ── Headers + MIME multipart body ─────────────────────────────────────
    $boundary = 'boundary_' . md5(uniqid());
    $headers  = 'From: =?UTF-8?B?' . base64_encode($name) . "?= <$from>\r\n"
              . 'To: =?UTF-8?B?' . base64_encode($toName) . "?= <$toEmail>\r\n"
              . 'Subject: =?UTF-8?B?' . base64_encode($subject) . "?=\r\n"
              . "MIME-Version: 1.0\r\n"
              . "Content-Type: multipart/alternative; boundary=\"$boundary\"\r\n"
              . 'Date: ' . date('r') . "\r\n";

    $textBody = strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $htmlBody));
    $message  = $headers . "\r\n"
              . "--$boundary\r\n"
              . "Content-Type: text/plain; charset=UTF-8\r\n"
              . "Content-Transfer-Encoding: base64\r\n\r\n"
              . chunk_split(base64_encode($textBody)) . "\r\n"
              . "--$boundary\r\n"
              . "Content-Type: text/html; charset=UTF-8\r\n"
              . "Content-Transfer-Encoding: base64\r\n\r\n"
              . chunk_split(base64_encode($htmlBody)) . "\r\n"
              . "--$boundary--\r\n"
              . '.';

    $r = $send($message);
    if (substr($r, 0, 3) !== '250') {
        fclose($sock);
        return "Message rejected: $r";
    }

    $send('QUIT');
    fclose($sock);
    return true;
}
