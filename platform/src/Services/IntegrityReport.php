<?php
declare(strict_types=1);

namespace App\Services;

use App\Audit\AuditLogger;
use App\Core\Database;

/**
 * Nightly read-only integrity report (Design Rev3 §4.1, "IR"). Lists violations of service-layer rules.
 * It never corrects anything automatically.
 */
final class IntegrityReport
{
    public static function run(bool $record = true): array
    {
        $db = Database::get();
        $t = today_local();
        $checks = [
            'IR-02 roster outside service period' => "SELECT aa.activity_id, aa.volunteer_id FROM activity_assignments aa JOIN activities a ON a.id = aa.activity_id
                JOIN project_assignments pa ON pa.id = aa.project_assignment_id WHERE a.activity_date < pa.start_date OR (pa.end_date IS NOT NULL AND a.activity_date > pa.end_date)",
            'IR-03 roster on cancelled activity' => "SELECT aa.activity_id, aa.volunteer_id FROM activity_assignments aa JOIN activities a ON a.id = aa.activity_id WHERE a.status = 'cancelled'",
            'IR-05 present/late recorded before activity date' => "SELECT at.id FROM attendance at JOIN activities a ON a.id = at.activity_id WHERE at.status IN ('present','late') AND a.activity_date > '{$t}'",
            'IR-08 scheduled activity led by an ended supervisor' => "SELECT a.id FROM activities a JOIN project_supervisors ps ON ps.project_id = a.project_id AND ps.user_id = a.lead_supervisor_id
                WHERE a.status = 'scheduled' AND ps.ended_at IS NOT NULL",
            'IR-11 live time log outside service period' => "SELECT t.id FROM time_logs t JOIN project_assignments pa ON pa.id = t.project_assignment_id
                WHERE t.status IN ('pending','revision_requested','approved') AND (t.work_date < pa.start_date OR (pa.end_date IS NOT NULL AND t.work_date > pa.end_date))",
            'IR-11 activity log date differs from activity date' => "SELECT t.id FROM time_logs t JOIN activities a ON a.id = t.activity_id WHERE t.work_date <> a.activity_date",
            'IR-15 overlapping service periods' => "SELECT p1.id, p2.id AS other FROM project_assignments p1 JOIN project_assignments p2 ON p1.project_id = p2.project_id AND p1.volunteer_id = p2.volunteer_id
                AND p1.id < p2.id AND p1.start_date <= COALESCE(p2.end_date, '9999-12-31') AND p2.start_date <= COALESCE(p1.end_date, '9999-12-31')",
            'Self-approved time logs' => "SELECT id FROM time_logs WHERE reviewed_by IS NOT NULL AND reviewed_by = submitted_by AND status = 'approved'",
            'Daily total over 24 hours' => "SELECT volunteer_id, work_date FROM time_logs WHERE status IN ('pending','revision_requested','approved') GROUP BY volunteer_id, work_date HAVING SUM(minutes) > 1440",
            'Issued certificates now below required hours' => "SELECT c.id FROM certificates c WHERE c.status IN ('issued','delivered') AND c.required_hours IS NOT NULL
                AND (SELECT COALESCE(SUM(minutes),0) FROM time_logs t WHERE t.volunteer_id = c.volunteer_id AND t.status = 'approved') < c.required_hours * 60",
            'Generated-column consistency' => "SELECT id FROM volunteer_applications WHERE NOT (open_email <=> CASE WHEN status IN ('pending','under_review') THEN email END)",
        ];
        $out = [];
        $total = 0;
        foreach ($checks as $name => $sql) {
            $rows = $db->all($sql . ' LIMIT 100');
            $out[] = ['check' => $name, 'count' => count($rows), 'sample' => $rows];
            $total += count($rows);
        }
        if ($record) {
            AuditLogger::record('integrity.report', $total ? 'failed' : 'success', null, null, ['violations' => $total]);
        }
        return ['checked_at' => now_utc(), 'violations' => $total, 'checks' => $out];
    }
}
