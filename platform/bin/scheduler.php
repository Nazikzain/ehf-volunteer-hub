<?php
declare(strict_types=1);
/*
 * Scheduled maintenance. On Hostinger add a cron job, e.g. every 15 minutes:
 *   php /home/<account>/ehf/bin/scheduler.php
 * Where cron is unavailable the daily parts also run on the first admin request of the day.
 */
require __DIR__ . '/../src/bootstrap.php';
if (PHP_SAPI !== 'cli') { exit(1); }
$out = ['mail' => App\Services\Mailer::process(50)];
if (App\Services\Settings::get('scheduler.daily') !== gmdate('Y-m-d')) {
    $out['anchor'] = App\Audit\AuditAnchor::dailyIfDue();
    $out['retention'] = App\Services\Retention::run();
    $out['integrity_violations'] = App\Services\IntegrityReport::run()['violations'];
    $v = App\Audit\AuditVerifier::verify();
    $out['audit'] = $v['result'];
    if ($v['result'] === 'red') {
        App\Services\Notifier::notifyAdmins('audit', 'Audit verification FAILED', 'Open the audit log and investigate immediately.', '/admin/audit');
    }
    App\Services\Settings::set('scheduler.daily', gmdate('Y-m-d'), null);
}
echo json_encode($out, JSON_PRETTY_PRINT), "\n";
