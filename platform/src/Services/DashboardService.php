<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Security\Auth;

/** Dashboard figures. Every number is a live query; supervisor figures are scoped to supervised projects. */
final class DashboardService
{
    public static function admin(): array
    {
        $db = Database::get();
        $today = today_local();
        $k = $db->one("SELECT
            (SELECT COUNT(*) FROM volunteers WHERE deleted_at IS NULL AND anonymised_at IS NULL) AS volunteers_total,
            (SELECT COUNT(*) FROM volunteers WHERE status = 'active' AND deleted_at IS NULL) AS volunteers_active,
            (SELECT COUNT(*) FROM volunteers WHERE status = 'inactive' AND deleted_at IS NULL) AS volunteers_inactive,
            (SELECT COUNT(*) FROM volunteer_applications WHERE status = 'pending') AS apps_pending,
            (SELECT COUNT(*) FROM volunteer_applications WHERE status = 'under_review') AS apps_review,
            (SELECT COUNT(*) FROM projects WHERE status = 'active' AND deleted_at IS NULL) AS projects_active,
            (SELECT COUNT(*) FROM projects WHERE status = 'completed' AND deleted_at IS NULL) AS projects_completed,
            (SELECT COUNT(*) FROM activities WHERE status = 'scheduled' AND activity_date >= :t) AS activities_upcoming,
            (SELECT COUNT(*) FROM activities WHERE status = 'scheduled' AND lead_supervisor_id IS NULL AND activity_date >= :t2) AS activities_no_lead,
            (SELECT COUNT(*) FROM time_logs WHERE status = 'pending') AS hours_pending,
            (SELECT COALESCE(SUM(minutes), 0) FROM time_logs WHERE status = 'approved') AS minutes_approved,
            (SELECT COUNT(*) FROM certificates WHERE status IN ('issued','delivered')) AS certificates_issued",
            ['t' => $today, 't2' => $today]);
        $k['attendance'] = self::attendanceRate(null);
        $k['missing_attendance'] = (int) $db->value("SELECT COUNT(*) FROM activity_assignments aa JOIN activities a ON a.id = aa.activity_id
            LEFT JOIN attendance at ON at.activity_id = aa.activity_id AND at.volunteer_id = aa.volunteer_id
            WHERE a.status <> 'cancelled' AND a.activity_date < :t AND at.id IS NULL", ['t' => $today]);
        $k['training_expiring'] = (int) $db->value("SELECT COUNT(*) FROM volunteer_training WHERE expires_on BETWEEN :t AND DATE_ADD(:t2, INTERVAL 30 DAY)",
            ['t' => $today, 't2' => $today]);
        $k['fallback_pending'] = count(\App\Audit\FallbackLog::pending());
        $k['monthly'] = $db->all("SELECT DATE_FORMAT(work_date, '%Y-%m') AS ym, SUM(minutes) AS minutes FROM time_logs
            WHERE status = 'approved' AND work_date >= DATE_SUB(:t, INTERVAL 11 MONTH) GROUP BY ym ORDER BY ym", ['t' => $today]);
        $k['applications_monthly'] = $db->all("SELECT DATE_FORMAT(submitted_at, '%Y-%m') AS ym, COUNT(*) AS n FROM volunteer_applications
            WHERE submitted_at >= DATE_SUB(:t, INTERVAL 11 MONTH) GROUP BY ym ORDER BY ym", ['t' => $today]);
        return $k;
    }

    public static function attendanceRate(?array $projectIds): ?float
    {
        $sql = "SELECT SUM(at.status IN ('present','late')) AS att, COUNT(aa.volunteer_id) AS rostered FROM activity_assignments aa
            JOIN activities a ON a.id = aa.activity_id LEFT JOIN attendance at ON at.activity_id = aa.activity_id AND at.volunteer_id = aa.volunteer_id
            WHERE a.status = 'completed'";
        if ($projectIds !== null) {
            if (!$projectIds) {
                return null;
            }
            $sql .= ' AND a.project_id IN (' . implode(',', array_map('intval', $projectIds)) . ')';
        }
        $r = Database::get()->one($sql);
        return ((int) $r['rostered']) > 0 ? round(100 * (int) $r['att'] / (int) $r['rostered'], 1) : null;
    }

    public static function supervisor(): array
    {
        $ids = Auth::supervisedProjectIds();
        $db = Database::get();
        if (!$ids) {
            return ['projects' => [], 'activities' => [], 'hours_pending' => 0, 'attendance' => null, 'missing_attendance' => 0];
        }
        $in = implode(',', array_map('intval', $ids));
        $t = today_local();
        return [
            'projects' => $db->all("SELECT p.id, p.name, p.status, (SELECT COUNT(*) FROM project_assignments pa WHERE pa.project_id = p.id AND pa.status = 'active') AS vols
                FROM projects p WHERE p.id IN ({$in}) ORDER BY p.name"),
            'activities' => $db->all("SELECT a.*, p.name AS project_name FROM activities a JOIN projects p ON p.id = a.project_id
                WHERE a.project_id IN ({$in}) AND a.status = 'scheduled' AND a.activity_date >= ? ORDER BY a.activity_date, a.start_time LIMIT 10", [$t]),
            'hours_pending' => (int) $db->value("SELECT COUNT(*) FROM time_logs WHERE project_id IN ({$in}) AND status = 'pending'"),
            'attendance' => self::attendanceRate($ids),
            'missing_attendance' => (int) $db->value("SELECT COUNT(*) FROM activity_assignments aa JOIN activities a ON a.id = aa.activity_id
                LEFT JOIN attendance at ON at.activity_id = aa.activity_id AND at.volunteer_id = aa.volunteer_id
                WHERE a.project_id IN ({$in}) AND a.status <> 'cancelled' AND a.activity_date < ? AND at.id IS NULL", [$t]),
        ];
    }

    public static function volunteer(): array
    {
        $vid = Auth::volunteerId();
        $db = Database::get();
        if ($vid === null) {
            return ['v' => null];
        }
        $t = today_local();
        return [
            'v' => $db->one('SELECT id, full_name, status FROM volunteers WHERE id = ?', [$vid]),
            'upcoming' => $db->all("SELECT a.*, p.name AS project_name FROM activity_assignments aa JOIN activities a ON a.id = aa.activity_id
                JOIN projects p ON p.id = a.project_id WHERE aa.volunteer_id = ? AND a.status = 'scheduled' AND a.activity_date >= ?
                ORDER BY a.activity_date, a.start_time LIMIT 10", [$vid, $t]),
            'minutes' => (int) $db->value("SELECT COALESCE(SUM(minutes), 0) FROM time_logs WHERE volunteer_id = ? AND status = 'approved'", [$vid]),
            'pending' => (int) $db->value("SELECT COUNT(*) FROM time_logs WHERE volunteer_id = ? AND status IN ('pending','revision_requested')", [$vid]),
            'attended' => (int) $db->value("SELECT COUNT(*) FROM attendance WHERE volunteer_id = ? AND status IN ('present','late')", [$vid]),
            'projects' => $db->all("SELECT p.id, p.name, pa.status, pa.start_date, pa.end_date FROM project_assignments pa JOIN projects p ON p.id = pa.project_id
                WHERE pa.volunteer_id = ? ORDER BY pa.status = 'active' DESC, pa.start_date DESC", [$vid]),
        ];
    }

    public static function announcements(): array
    {
        $aud = ['all'];
        if (Auth::isAdmin()) { $aud[] = 'admins'; }
        if (Auth::hasRole('project_supervisor')) { $aud[] = 'supervisors'; }
        if (Auth::volunteerId() !== null) { $aud[] = 'volunteers'; }
        $ph = implode(',', array_fill(0, count($aud), '?'));
        $projects = array_merge(Auth::supervisedProjectIds(), Auth::volunteerId() ? array_map('intval', array_column(Database::get()->all(
            "SELECT project_id FROM project_assignments WHERE volunteer_id = ? AND status = 'active'", [Auth::volunteerId()]), 'project_id')) : []);
        $projSql = $projects ? ' OR (audience = \'project\' AND project_id IN (' . implode(',', array_map('intval', $projects)) . '))' : '';
        if (Auth::isAdmin()) { $projSql = " OR audience = 'project'"; }
        return Database::get()->all("SELECT * FROM announcements WHERE deleted_at IS NULL AND published_at IS NOT NULL AND published_at <= ?
            AND (expires_at IS NULL OR expires_at > ?) AND (audience IN ({$ph}){$projSql}) ORDER BY published_at DESC LIMIT 10",
            array_merge([now_utc(), now_utc()], $aud));
    }
}
