<?php
declare(strict_types=1);

namespace App\Services;

use App\Audit\AuditLogger;
use App\Core\Database;
use App\Core\ValidationException;
use App\Security\Auth;

/**
 * Reports respect the same scope as the screens (Design Rev3 §8.1 item 5):
 * admins all projects, supervisors only actively supervised projects.
 */
final class ReportService
{
    /** @return array{from:string,to:string,label:string} */
    public static function period(string $type, string $value): array
    {
        if ($type === 'month' && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $value)) {
            $from = $value . '-01';
            return ['from' => $from, 'to' => (new \DateTimeImmutable($from))->modify('last day of this month')->format('Y-m-d'), 'label' => $value];
        }
        if ($type === 'year' && preg_match('/^\d{4}$/', $value)) {
            return ['from' => "{$value}-01-01", 'to' => "{$value}-12-31", 'label' => $value];
        }
        throw new ValidationException(['Choose a valid month (YYYY-MM) or year (YYYY).']);
    }

    private static function projectScope(string $col, array &$p): string
    {
        $f = Scope::supervisorFilter($col, $p);
        return $f === null ? '' : " AND {$f}";
    }

    public static function summary(array $period, ?int $projectId): array
    {
        $db = Database::get();
        $p = ['f' => $period['from'], 't' => $period['to']];
        $scope = self::projectScope('x.project_id', $p);
        if ($projectId) { $scope .= ' AND x.project_id = :pid'; $p['pid'] = $projectId; }
        $hours = $db->all("SELECT pr.name AS project, COUNT(DISTINCT x.volunteer_id) AS volunteers, SUM(x.minutes) AS minutes
            FROM time_logs x JOIN projects pr ON pr.id = x.project_id WHERE x.status = 'approved' AND x.work_date BETWEEN :f AND :t{$scope}
            GROUP BY pr.id ORDER BY pr.name", $p);
        $p2 = ['f' => $period['from'], 't' => $period['to']];
        $scope2 = self::projectScope('x.project_id', $p2);
        if ($projectId) { $scope2 .= ' AND x.project_id = :pid'; $p2['pid'] = $projectId; }
        $acts = $db->all("SELECT pr.name AS project, COUNT(*) AS activities, SUM(x.status = 'completed') AS completed, SUM(x.status = 'cancelled') AS cancelled,
            (SELECT COUNT(*) FROM activity_assignments aa JOIN activities a2 ON a2.id = aa.activity_id WHERE a2.project_id = pr.id AND a2.status = 'completed' AND a2.activity_date BETWEEN :f2 AND :t2) AS rostered,
            (SELECT COUNT(*) FROM attendance at JOIN activities a3 ON a3.id = at.activity_id WHERE a3.project_id = pr.id AND a3.status = 'completed' AND a3.activity_date BETWEEN :f3 AND :t3 AND at.status IN ('present','late')) AS attended
            FROM activities x JOIN projects pr ON pr.id = x.project_id WHERE x.activity_date BETWEEN :f AND :t{$scope2} GROUP BY pr.id ORDER BY pr.name",
            $p2 + ['f2' => $period['from'], 't2' => $period['to'], 'f3' => $period['from'], 't3' => $period['to']]);
        $out = ['hours' => $hours, 'activities' => $acts, 'total_minutes' => array_sum(array_column($hours, 'minutes'))];
        if (Auth::isAdmin() && !$projectId) {
            $out['applications'] = $db->one("SELECT COUNT(*) AS received, SUM(status = 'approved') AS approved, SUM(status = 'rejected') AS rejected
                FROM volunteer_applications WHERE submitted_at BETWEEN :f AND :t", ['f' => $period['from'] . ' 00:00:00', 't' => $period['to'] . ' 23:59:59']);
            $out['new_volunteers'] = (int) $db->value("SELECT COUNT(*) FROM volunteer_status_history WHERE from_status IS NULL AND changed_at BETWEEN ? AND ?",
                [$period['from'] . ' 00:00:00', $period['to'] . ' 23:59:59']);
            $out['active_at_end'] = (int) $db->value("SELECT COUNT(*) FROM volunteers v WHERE v.deleted_at IS NULL AND
                (SELECT h.to_status FROM volunteer_status_history h WHERE h.volunteer_id = v.id AND h.changed_at <= ? ORDER BY h.changed_at DESC, h.id DESC LIMIT 1) = 'active'",
                [$period['to'] . ' 23:59:59']);
            $out['skills'] = $db->all('SELECT s.name, COUNT(vs.volunteer_id) AS n FROM skills s LEFT JOIN volunteer_skills vs ON vs.skill_id = s.id
                LEFT JOIN volunteers v ON v.id = vs.volunteer_id AND v.status = \'active\' GROUP BY s.id ORDER BY n DESC, s.name');
        }
        return $out;
    }

    /** CSV rows for an export; personal data exports are audited with row counts. */
    public static function export(string $kind, array $period, ?int $projectId): array
    {
        $db = Database::get();
        $p = ['f' => $period['from'], 't' => $period['to']];
        $scope = self::projectScope('x.project_id', $p);
        if ($projectId) { $scope .= ' AND x.project_id = :pid'; $p['pid'] = $projectId; }
        switch ($kind) {
            case 'hours':
                $header = ['Volunteer', 'Project', 'Date', 'Hours', 'Activity', 'Task'];
                $rows = $db->all("SELECT v.full_name, pr.name, x.work_date, ROUND(x.minutes / 60, 2), a.title, k.title FROM time_logs x
                    JOIN volunteers v ON v.id = x.volunteer_id JOIN projects pr ON pr.id = x.project_id LEFT JOIN activities a ON a.id = x.activity_id
                    LEFT JOIN tasks k ON k.id = x.task_id WHERE x.status = 'approved' AND x.work_date BETWEEN :f AND :t{$scope} ORDER BY x.work_date", $p);
                $personal = true;
                break;
            case 'attendance':
                $header = ['Volunteer', 'Project', 'Activity', 'Date', 'Attendance'];
                $rows = $db->all("SELECT v.full_name, pr.name, a.title, a.activity_date, COALESCE(at.status, 'not recorded') FROM activity_assignments x
                    JOIN activities a ON a.id = x.activity_id JOIN volunteers v ON v.id = x.volunteer_id JOIN projects pr ON pr.id = x.project_id
                    LEFT JOIN attendance at ON at.activity_id = x.activity_id AND at.volunteer_id = x.volunteer_id
                    WHERE a.activity_date BETWEEN :f AND :t{$scope} ORDER BY a.activity_date", $p);
                $personal = true;
                break;
            case 'projects':
                $header = ['Project', 'Volunteers with approved hours', 'Approved hours'];
                $rows = array_map(fn($r) => [$r['project'], $r['volunteers'], round($r['minutes'] / 60, 2)], self::summary($period, $projectId)['hours']);
                $personal = false;
                break;
            default:
                throw new ValidationException(['Unknown export.']);
        }
        AuditLogger::record($personal ? 'export.personal_data' : 'report.export', 'success', null, null,
            ['kind' => $kind, 'rows' => count($rows), 'from' => $period['from'], 'to' => $period['to'], 'project' => $projectId === null ? 'all' : (string) $projectId]);
        return [$header, array_map('array_values', $rows)];
    }
}
