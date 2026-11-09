<?php
declare(strict_types=1);

namespace App\Audit;

use App\Core\Database;

/**
 * External anchoring (Design Rev3 §6.8a). Daily, the head (seq, hash) is written to a manifest
 * file for off-site storage, emailed to the Primary Admin where SMTP is configured, and recorded.
 * The anchor kept in settings is a convenience copy; the evidential copies are the ones held off-host.
 */
final class AuditAnchor
{
    public static function latest(): ?array
    {
        $v = Database::get()->value("SELECT value FROM settings WHERE `key` = 'audit.last_anchor'");
        return $v ? json_decode((string) $v, true) : null;
    }

    public static function dailyIfDue(): ?array
    {
        $last = self::latest();
        if ($last !== null && ($last['date'] ?? '') === gmdate('Y-m-d')) {
            return null;
        }
        return self::create();
    }

    public static function create(): array
    {
        $db = Database::get();
        $head = $db->one("SELECT last_seq, last_hash FROM audit_chain WHERE singleton = 'head'");
        $anchor = ['date' => gmdate('Y-m-d'), 'created_at' => now_utc(), 'seq' => (int) $head['last_seq'],
            'hash' => bin2hex($head['last_hash'])];
        $json = json_encode($anchor, JSON_PRETTY_PRINT);
        $dir = export_dir();
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        file_put_contents($dir . '/audit-anchor-' . $anchor['date'] . '.json', $json);
        $db->run("INSERT INTO settings (`key`, value, updated_by, updated_at) VALUES ('audit.last_anchor', :v, NULL, :u)
                  ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = VALUES(updated_at)", ['v' => $json, 'u' => now_utc()]);
        AuditLogger::record('audit.anchor', 'success', null, null, ['seq' => $anchor['seq'], 'hash' => $anchor['hash']]);
        $owner = $db->one('SELECT u.id, u.email FROM system_owner s JOIN users u ON u.id = s.user_id');
        if ($owner) {
            \App\Services\Mailer::queue($owner['email'], 'audit_anchor', 'EHF Volunteer Hub: daily audit anchor',
                "Daily audit anchor for {$anchor['date']} (UTC):\n\nsequence: {$anchor['seq']}\nhash: {$anchor['hash']}\n\n"
                . "Please keep this email. It is independent evidence of the audit log's state.");
        }
        return $anchor;
    }
}
