<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Email outbox (Design Rev3 §5 F3). Transports:
 *  - log      : development only; writes to storage/logs/mail.log (never use in production)
 *  - smtp     : SMTP with STARTTLS/SSL and AUTH LOGIN (credentials in .env only)
 *  - disabled : messages stay queued (e.g. staging without SMTP)
 * Bodies are erased once sent or finally failed (DB CHECK enforces it).
 */
final class Mailer
{
    public static function queue(string $to, string $template, string $subject, string $body): int
    {
        return Database::get()->insert('email_outbox', ['to_email' => $to, 'template' => $template, 'subject' => $subject,
            'body' => $body, 'status' => 'queued', 'attempts' => 0, 'available_at' => now_utc(), 'created_at' => now_utc()]);
    }

    /** @return array{sent:int, failed:int} */
    public static function process(int $limit = 20): array
    {
        $db = Database::get();
        $transport = (string) env('MAIL_TRANSPORT', 'log');
        $stats = ['sent' => 0, 'failed' => 0];
        if ($transport === 'disabled') {
            return $stats;
        }
        $rows = $db->all("SELECT * FROM email_outbox WHERE status = 'queued' AND available_at <= ? ORDER BY id LIMIT {$limit}", [now_utc()]);
        foreach ($rows as $m) {
            if ($db->run("UPDATE email_outbox SET status = 'sending', attempts = attempts + 1 WHERE id = ? AND status = 'queued'", [$m['id']])->rowCount() !== 1) {
                continue;
            }
            try {
                $transport === 'smtp' ? SmtpClient::send($m['to_email'], $m['subject'], (string) $m['body'])
                                      : self::logTransport($m);
                $db->run("UPDATE email_outbox SET status = 'sent', sent_at = ?, body = NULL, purged_at = ?, last_error = NULL WHERE id = ?",
                    [now_utc(), now_utc(), $m['id']]);
                $stats['sent']++;
            } catch (\Throwable $e) {
                $final = (int) $m['attempts'] + 1 >= 5;
                $err = mb_substr(\App\Core\ErrorHandler::redact($e->getMessage()), 0, 480);
                if ($final) {
                    $db->run("UPDATE email_outbox SET status = 'failed', body = NULL, purged_at = ?, last_error = ? WHERE id = ?", [now_utc(), $err, $m['id']]);
                } else {
                    $db->run("UPDATE email_outbox SET status = 'queued', last_error = ?, available_at = ? WHERE id = ?",
                        [$err, gmdate('Y-m-d H:i:s', time() + 300 * ((int) $m['attempts'] + 1)), $m['id']]);
                }
                $stats['failed']++;
            }
        }
        return $stats;
    }

    private static function logTransport(array $m): void
    {
        if (env('APP_ENV') === 'production') {
            throw new \RuntimeException('log mail transport is not permitted in production');
        }
        $entry = sprintf("=== %s UTC | to: %s | subject: %s ===\n%s\n\n", now_utc(), $m['to_email'], $m['subject'], $m['body']);
        file_put_contents(APP_ROOT . '/storage/logs/mail.log', $entry, FILE_APPEND | LOCK_EX);
    }
}
