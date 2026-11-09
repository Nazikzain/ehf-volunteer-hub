<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Audit\AuditAnchor;
use App\Audit\AuditLogger;
use App\Audit\AuditVerifier;
use App\Audit\FallbackLog;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\ValidationException;
use App\Security\Auth;
use App\Services\AnnouncementService;
use App\Services\IntegrityReport;
use App\Services\Mailer;
use App\Services\ProjectService;
use App\Services\ReportService;
use App\Services\Settings;

final class AdminController extends Controller
{
    // ---- announcements -------------------------------------------------------------------------
    public function announcements(Request $r): Response
    {
        $manage = \App\Security\Gate::allows('announcements.manage');
        return $this->view('admin/announcements', ['title' => 'Announcements', 'visible' => \App\Services\DashboardService::announcements(),
            'mine' => $manage ? AnnouncementService::manageable() : [], 'manage' => $manage,
            'projects' => array_values(array_filter(ProjectService::list([]), fn($p) => Auth::isAdmin() || Auth::supervises((int) $p['id'])))]);
    }

    public function createAnnouncement(Request $r): Response
    {
        return $this->attempt(function () use ($r) {
            AnnouncementService::create($r->post);
            return $this->redirect('/announcements', 'Announcement published.');
        }, '/announcements');
    }

    public function deleteAnnouncement(Request $r): Response
    {
        AnnouncementService::delete($r->param('id'));
        return $this->redirect('/announcements', 'Announcement removed.');
    }

    // ---- reports -------------------------------------------------------------------------------
    public function reports(Request $r): Response
    {
        $type = $r->input('type') === 'year' ? 'year' : 'month';
        $value = (string) $r->input('value', $type === 'year' ? gmdate('Y') : gmdate('Y-m'));
        $pid = $r->int('project');
        if ($pid !== null) {
            \App\Services\Scope::assertManageProject($pid);
        }
        try {
            $period = ReportService::period($type, $value);
            $data = ReportService::summary($period, $pid);
        } catch (ValidationException $e) {
            return $this->redirect('/reports', $e->getMessage(), 'error');
        }
        return $this->view('admin/reports', ['title' => 'Reports', 'type' => $type, 'value' => $value, 'period' => $period, 'd' => $data, 'pid' => $pid,
            'projects' => array_values(array_filter(ProjectService::list([]), fn($p) => Auth::isAdmin() || Auth::supervises((int) $p['id'])))]);
    }

    public function export(Request $r): Response
    {
        \App\Security\Gate::authorize('reports.export');
        $pid = $r->int('project');
        if ($pid !== null) {
            \App\Services\Scope::assertManageProject($pid);
        }
        try {
            $period = ReportService::period($r->input('type') === 'year' ? 'year' : 'month', (string) $r->input('value', ''));
            [$h, $rows] = ReportService::export((string) $r->input('kind'), $period, $pid);
        } catch (ValidationException $e) {
            return $this->redirect('/reports', $e->getMessage(), 'error');
        }
        return Response::csv('ehf-' . $r->input('kind') . '-' . $period['label'] . '.csv', $h, $rows);
    }

    // ---- audit ---------------------------------------------------------------------------------
    public function audit(Request $r): Response
    {
        $where = ['1=1'];
        $p = [];
        if ($a = (string) $r->input('action', '')) { $where[] = 'action = :a'; $p['a'] = $a; }
        if ($o = (string) $r->input('outcome', '')) { $where[] = 'outcome = :o'; $p['o'] = $o; }
        if ($tt = (string) $r->input('target_type', '')) { $where[] = 'target_type = :tt'; $p['tt'] = $tt; }
        if ($tid = $r->int('target_id')) { $where[] = 'target_id = :tid'; $p['tid'] = $tid; }
        if ($u = $r->int('actor')) { $where[] = 'actor_user_id = :u'; $p['u'] = $u; }
        $rows = Database::get()->all('SELECT seq, occurred_at, recorded_at, source, actor_user_id, actor_email, actor_role, action, outcome, target_type, target_id, details
            FROM audit_logs WHERE ' . implode(' AND ', $where) . ' ORDER BY seq DESC LIMIT 300', $p);
        return $this->view('admin/audit', ['title' => 'Audit log', 'rows' => $rows, 'f' => $r->query, 'pending' => count(FallbackLog::pending()),
            'anchor' => AuditAnchor::latest(), 'actions' => AuditLogger::ACTIONS]);
    }

    public function auditVerify(Request $r): Response
    {
        $rep = AuditVerifier::verify();
        return $this->view('admin/audit_verify', ['title' => 'Audit verification', 'rep' => $rep]);
    }

    public function auditReconcile(Request $r): Response
    {
        \App\Security\Gate::authorize('audit.reconcile');
        $s = FallbackLog::reconcile();
        return $this->redirect('/admin/audit', "Reconciled {$s['files']} file(s): {$s['imported']} imported, {$s['rejected']} rejected (see audit entries).",
            $s['rejected'] ? 'warning' : 'success');
    }

    public function integrity(Request $r): Response
    {
        return $this->view('admin/integrity', ['title' => 'Integrity report', 'rep' => IntegrityReport::run()]);
    }

    // ---- outbox & settings ---------------------------------------------------------------------
    public function outbox(Request $r): Response
    {
        $rows = Database::get()->all('SELECT id, to_email, template, subject, status, attempts, last_error, created_at, sent_at FROM email_outbox ORDER BY id DESC LIMIT 200');
        return $this->view('admin/outbox', ['title' => 'Email outbox', 'rows' => $rows, 'transport' => env('MAIL_TRANSPORT', 'log')]);
    }

    public function processOutbox(Request $r): Response
    {
        $s = Mailer::process(50);
        return $this->redirect('/admin/outbox', "Processed: {$s['sent']} sent, {$s['failed']} failed.");
    }

    public const EDITABLE = ['org.name' => 'Organisation name', 'org.charity_number' => 'Charity number', 'consent.version' => 'Consent text version',
        'consent.text' => 'Consent text shown on the application form', 'privacy.notice_url' => 'Privacy notice URL',
        'timelogs.late_submission_days' => 'Late submission window for hours (days) [D-17]',
        'timelogs.general_duplicate_policy' => 'Duplicate general-work logs: warn or block [D-19]',
        'retention.rejected_applications_months' => 'Retention of rejected/withdrawn applications (months) [D-07]',
        'retention.notifications_months' => 'Retention of notifications (months) [D-08]',
        'retention.fallback_files_days' => 'Retention of reconciled audit fallback files (days) [D-02]',
        'retention.auto_purge_enabled' => 'Apply personal-data retention purges automatically: true/false [D-25]'];

    public function settings(Request $r): Response
    {
        $vals = [];
        foreach (array_keys(self::EDITABLE) as $k) {
            $vals[$k] = Settings::get($k, '');
        }
        return $this->view('admin/settings', ['title' => 'Settings', 'vals' => $vals, 'labels' => self::EDITABLE, 'schema' => Settings::get('schema.migrations', '[]')]);
    }

    public function saveSettings(Request $r): Response
    {
        return $this->attempt(function () use ($r) {
            $changed = [];
            Database::get()->transaction(function () use ($r, &$changed) {
                foreach (array_keys(self::EDITABLE) as $k) {
                    $field = str_replace('.', '__', $k);
                    if (!array_key_exists($field, $r->post)) {
                        continue;
                    }
                    $v = trim((string) $r->post[$field]);
                    if (str_contains($k, 'months') || str_contains($k, 'days')) {
                        if (!ctype_digit($v) || (int) $v < 1 || (int) $v > 1200) { throw new ValidationException(["Enter a whole number for: " . self::EDITABLE[$k]]); }
                    }
                    if ($k === 'timelogs.general_duplicate_policy' && !in_array($v, ['warn', 'block'], true)) { throw new ValidationException(['Duplicate policy must be warn or block.']); }
                    if ($k === 'retention.auto_purge_enabled' && !in_array($v, ['true', 'false'], true)) { throw new ValidationException(['Auto purge must be true or false.']); }
                    if ($k === 'privacy.notice_url' && !preg_match('#^https://#', $v)) { throw new ValidationException(['The privacy notice URL must start with https://']); }
                    if (Settings::get($k) !== $v) {
                        Settings::set($k, mb_substr($v, 0, 2000), Auth::id());
                        $changed[] = $k;
                    }
                }
                if ($changed) {
                    AuditLogger::record('settings.update', 'success', null, null, ['keys' => $changed]);
                }
            });
            return $this->redirect('/admin/settings', $changed ? 'Settings saved.' : 'No changes.');
        }, '/admin/settings');
    }
}
