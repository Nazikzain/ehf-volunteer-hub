<?php
declare(strict_types=1);

namespace App\Services;

/** Minimal SMTP client (STARTTLS or implicit TLS, AUTH LOGIN). Plain-text UTF-8 messages. */
final class SmtpClient
{
    public static function send(string $to, string $subject, string $body): void
    {
        $host = (string) env('SMTP_HOST');
        $port = (int) env('SMTP_PORT', '587');
        $secure = (string) env('SMTP_SECURE', 'tls'); // tls (STARTTLS) | ssl
        $from = (string) env('MAIL_FROM');
        if ($host === '' || $from === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException('SMTP not configured or invalid recipient');
        }
        $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
        $remote = ($secure === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
        $fp = stream_socket_client($remote, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
        if (!$fp) {
            throw new \RuntimeException("SMTP connect failed ({$errno})");
        }
        stream_set_timeout($fp, 15);
        $read = function () use ($fp): string {
            $data = '';
            while (($line = fgets($fp, 515)) !== false) {
                $data .= $line;
                if (isset($line[3]) && $line[3] === ' ') {
                    break;
                }
            }
            return $data;
        };
        $cmd = function (string $c, array $ok) use ($fp, $read): string {
            fwrite($fp, $c . "\r\n");
            $r = $read();
            if (!in_array((int) substr($r, 0, 3), $ok, true)) {
                throw new \RuntimeException('SMTP error at ' . strtok($c, ' ') . ': ' . substr($r, 0, 3));
            }
            return $r;
        };
        try {
            $read();
            $ehlo = 'EHLO ' . (parse_url((string) env('APP_URL', 'http://localhost'), PHP_URL_HOST) ?: 'localhost');
            $cmd($ehlo, [250]);
            if ($secure === 'tls') {
                $cmd('STARTTLS', [220]);
                if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) {
                    throw new \RuntimeException('STARTTLS failed');
                }
                $cmd($ehlo, [250]);
            }
            if (env('SMTP_USER')) {
                $cmd('AUTH LOGIN', [334]);
                $cmd(base64_encode((string) env('SMTP_USER')), [334]);
                $cmd(base64_encode((string) env('SMTP_PASS')), [235]);
            }
            $cmd('MAIL FROM:<' . $from . '>', [250]);
            $cmd('RCPT TO:<' . $to . '>', [250, 251]);
            $cmd('DATA', [354]);
            $headers = "From: " . env('MAIL_FROM_NAME', 'EHF Volunteer Hub') . " <{$from}>\r\nTo: <{$to}>\r\n"
                . 'Subject: =?UTF-8?B?' . base64_encode($subject) . "?=\r\nMIME-Version: 1.0\r\n"
                . "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n"
                . 'Date: ' . gmdate('D, d M Y H:i:s') . " +0000\r\n";
            $cmd($headers . "\r\n" . chunk_split(base64_encode($body)) . "\r\n.", [250]);
            $cmd('QUIT', [221]);
        } finally {
            fclose($fp);
        }
    }
}
