<?php
declare(strict_types=1);

namespace App\Services;

use App\Audit\FallbackLog;
use App\Core\Database;
use App\Security\RateLimiter;
use App\Security\Tokens;

/**
 * Retention clean-up using the conservative defaults in settings (shortest defensible periods; D-07, D-08, D-02).
 * Audit log retention/archiving is a manual Primary Admin procedure (Design §6.11) and is NOT automated here.
 */
final class Retention
{
    /**
     * Technical purges (rate-limit counters, expired tokens) always run. Personal-data purges are irreversible, so they
     * run only when settings 'retention.auto_purge_enabled' = 'true' (EHF/DPL decision D-25); otherwise counts are reported.
     */
    public static function run(): array
    {
        $db = Database::get();
        $apply = Settings::get('retention.auto_purge_enabled', 'false') === 'true';
        $appMonths = max(1, (int) Settings::get('retention.rejected_applications_months', '12'));
        $notifMonths = max(1, (int) Settings::get('retention.notifications_months', '12'));
        $fbDays = max(1, (int) Settings::get('retention.fallback_files_days', '90'));
        $rules = [
            'notifications' => "FROM notifications WHERE created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL {$notifMonths} MONTH)",
            'applications' => "FROM volunteer_applications WHERE status IN ('rejected','withdrawn') AND volunteer_id IS NULL
                AND reviewed_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL {$appMonths} MONTH)",
            'outbox' => "FROM email_outbox WHERE status IN ('sent','failed','cancelled') AND created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 90 DAY)",
        ];
        $res = ['mode' => $apply ? 'applied' : 'report_only (enable retention.auto_purge_enabled after EHF approval)',
            'rate_limit_hits' => RateLimiter::purge(), 'auth_tokens' => Tokens::purge(), 'fallback_files' => 0];
        foreach ($rules as $k => $from) {
            $res[$k] = $apply ? $db->run("DELETE {$from}")->rowCount() : (int) $db->value("SELECT COUNT(*) {$from}");
        }
        if (!$apply) {
            return $res;
        }
        foreach (glob(FallbackLog::dir() . '/reconciled/*.jsonl') ?: [] as $f) {
            if (filemtime($f) < time() - $fbDays * 86400 && unlink($f)) {
                $res['fallback_files']++;
            }
        }
        return $res;
    }
}
