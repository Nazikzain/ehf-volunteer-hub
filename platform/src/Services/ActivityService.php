<?php
declare(strict_types=1);

namespace App\Services;

use App\Audit\AuditLogger;
use App\Core\Database;
use App\Core\ValidationException;
use App\Security\Auth;
use App\Security\Gate;

/** Activities, rostering and attendance (Design Rev3 §4.1 IR-01..IR-09). */
final class ActivityService
{
    public static function list(array $f): array
    {
        $params = [];
        $where = ['1=1'];
        if (Auth::isAdmin() || Auth::supervisedProjectIds()) {
            if ($s = Scope::projectFilter('a.project_id', $params)) { $where[] = $s; }
        } else {
            // volunteers: only activities they are rostered on
            $where[] = 'EXISTS (SELECT 1 FROM activity_assignments aa WHERE aa.activity_id = a.id AND aa.volunteer_id = :me)';
            $params['me'] = (int) Auth::volunteerId();
        }
        if (!empty($f['project'])) { $where[] = 'a.project_id = :p'; $params['p'] = (int) $f['project']; }
        if (!empty($f['status'])) { $where[] = 'a.status = :st'; $params['st'] = $f['status']; }
        if (!empty($f['from'])) { $where[] = 'a.activity_date >= :fr'; $params['fr'] = $f['from']; }
        if (!empty($f['to'])) { $where[] = 'a.activity_date <= :to'; $params['to'] = $f['to']; }
        return Database::get()->all('SELECT a.*, p.name AS project_name, u.email AS lead_email,
            (SELECT COUNT(*) FROM activity_assignments aa2 WHERE aa2.activity_id = a.id) AS rostered,
            (SELECT COUNT(*) FROM attendance at WHERE at.activity_id = a.id) AS recorded
            FROM activities a JOIN projects p ON p.id = a.project_id LEFT JOIN users u ON u.id = a.lead_supervisor_id
            WHERE ' . implode(' AND ', $where) . ' ORDER BY a.activity_date DESC, a.start_time DESC LIMIT 300', $params);
    }

    public static function get(int $id): array
    {
        $db = Database::get();
        $a = $db->one('SELECT a.*, p.name AS project_name, p.status AS project_status, u.email AS lead_email FROM activities a
            JOIN projects p ON p.id = a.project_id LEFT JOIN users u ON u.id = a.lead_supervisor_id WHERE a.id = ?', [$id]);
        if (!$a) { Gate::notFound('activity'); }
        $pid = (int) $a['project_id'];
        $a['can_manage'] = Auth::isAdmin() || Auth::supervises($pid);
        if (!$a['can_manage']) {
            if (Auth::volunteerId() === null || !$db->value('SELECT 1 FROM activity_assignments WHERE activity_id = ? AND volunteer_id = ?', [$id, Auth::volunteerId()])) {
                Gate::notFound('activity');
            }
        }
        $rosterSql = 'SELECT aa.*, v.full_name, at.status AS attendance, at.recorded_at FROM activity_assignments aa JOIN volunteers v ON v.id = aa.volunteer_id
            LEFT JOIN attendance at ON at.activity_id = aa.activity_id AND at.volunteer_id = aa.volunteer_id WHERE aa.activity_id = ?';
        $args = [$id];
        if (!$a['can_manage']) { $rosterSql .= ' AND aa.volunteer_id = ?'; $args[] = Auth::volunteerId(); }
        $a['roster'] = $db->all($rosterSql . ' ORDER BY v.full_name', $args);
        $a['eligible'] = $a['can_manage'] ? $db->all("SELECT pa.id AS period_id, v.id, v.full_name FROM project_assignments pa JOIN volunteers v ON v.id = pa.volunteer_id
            WHERE pa.project_id = ? AND pa.status = 'active' AND pa.start_date <= ? AND (pa.end_date IS NULL OR pa.end_date >= ?)
            AND NOT EXISTS (SELECT 1 FROM activity_assignments x WHERE x.activity_id = ? AND x.volunteer_id = v.id) ORDER BY v.full_name",
            [$pid, $a['activity_date'], $a['activity_date'], $id]) : [];
        $a['leads'] = $a['can_manage'] ? $db->all('SELECT u.id, u.email FROM project_supervisors ps JOIN users u ON u.id = ps.user_id
            WHERE ps.project_id = ? AND ps.ended_at IS NULL ORDER BY u.email', [$pid]) : [];
        return $a;
    }

    private static function validate(Database $db, array $in, int $projectId): array
    {
        $e = [];
        $title = trim((string) ($in['title'] ?? ''));
        $date = (string) ($in['activity_date'] ?? '');
        $st = (string) ($in['start_time'] ?? '');
        $et = (string) ($in['end_time'] ?? '');
        $venue = trim((string) ($in['venue'] ?? ''));
        $lead = ($in['lead_supervisor_id'] ?? '') !== '' ? (int) $in['lead_supervisor_id'] : null;
        if ($title === '' || mb_strlen($title) > 180) { $e[] = 'Enter a title (up to 180 characters).'; }
        if (!ProjectService::isDate($date)) { $e[] = 'Enter a valid date.'; }
        if (!preg_match('/^\d{2}:\d{2}$/', $st) || !preg_match('/^\d{2}:\d{2}$/', $et)) { $e[] = 'Enter start and end times.'; }
        elseif ($et <= $st) { $e[] = 'The end time must be after the start time.'; }
        if ($venue === '' || mb_strlen($venue) > 200) { $e[] = 'Enter the venue.'; }
        if ($lead !== null && !$db->value('SELECT 1 FROM project_supervisors WHERE project_id = ? AND user_id = ? AND ended_at IS NULL', [$projectId, $lead])) {
            $e[] = 'The lead must be a current supervisor of this project.'; // IR-08
        }
        if ($e) { throw new ValidationException($e); }
        return ['title' => $title, 'description' => trim((string) ($in['description'] ?? '')) ?: null, 'activity_date' => $date,
            'start_time' => $st . ':00', 'end_time' => $et . ':00', 'venue' => $venue, 'lead_supervisor_id' => $lead];
    }

    public static function create(int $projectId, array $in): int
    {
        Gate::authorize('activities.manage');
        Scope::assertManageProject($projectId);
        return Database::get()->transaction(function (Database $db) use ($projectId, $in) {
            $p = $db->one('SELECT status FROM projects WHERE id = ? AND deleted_at IS NULL', [$projectId]);
            if (!$p) { Gate::notFound('project'); }
            if (in_array($p['status'], ['completed', 'cancelled'], true)) { throw new ValidationException(['Activities cannot be added to a completed or cancelled project.']); }
            $d = self::validate($db, $in, $projectId);
            $id = $db->insert('activities', $d + ['project_id' => $projectId, 'status' => 'scheduled', 'created_by' => Auth::id(), 'created_at' => now_utc(), 'updated_at' => now_utc()]);
            AuditLogger::record('activity.create', 'success', 'activity', $id, ['project' => (string) $projectId]);
            return $id;
        });
    }

    public static function update(int $id, array $in): void
    {
        Database::get()->transaction(function (Database $db) use ($id, $in) {
            $a = $db->one('SELECT * FROM activities WHERE id = ? FOR UPDATE', [$id]);
            if (!$a) { Gate::notFound('activity'); }
            Scope::assertManageProject((int) $a['project_id']);
            if ($a['status'] !== 'scheduled') { throw new ValidationException(['Only scheduled activities can be edited.']); }
            $d = self::validate($db, $in, (int) $a['project_id']);
            if ($d['activity_date'] !== $a['activity_date']) {
                // IR-02: every rostered volunteer's period must contain the new date
                $bad = (int) $db->value('SELECT COUNT(*) FROM activity_assignments aa JOIN project_assignments pa ON pa.id = aa.project_assignment_id
                    WHERE aa.activity_id = ? AND (pa.start_date > ? OR (pa.end_date IS NOT NULL AND pa.end_date < ?))', [$id, $d['activity_date'], $d['activity_date']]);
                if ($bad) { throw new ValidationException(["{$bad} rostered volunteer(s) are not in service on the new date. Remove them first."]); }
                if ($db->value('SELECT 1 FROM time_logs WHERE activity_id = ? AND status IN (\'pending\',\'revision_requested\',\'approved\')', [$id])) {
                    throw new ValidationException(['Hours have been logged against this activity, so its date cannot change.']);
                }
            }
            $db->update('activities', $d + ['updated_at' => now_utc()], 'id = :id', ['id' => $id]);
            AuditLogger::record('activity.update', 'success', 'activity', $id, ['fields' => array_keys(array_filter($d, fn($v, $k) => $a[$k] != $v, ARRAY_FILTER_USE_BOTH))]);
        });
    }

    public static function setStatus(int $id, string $to): void
    {
        if (!in_array($to, ['completed', 'cancelled', 'scheduled'], true)) { throw new ValidationException(['Unknown status.']); }
        Database::get()->transaction(function (Database $db) use ($id, $to) {
            $a = $db->one('SELECT * FROM activities WHERE id = ? FOR UPDATE', [$id]);
            if (!$a) { Gate::notFound('activity'); }
            Scope::assertManageProject((int) $a['project_id']);
            if ($to === 'completed' && $a['activity_date'] > today_local()) { throw new ValidationException(['A future activity cannot be marked completed.']); }
            if ($to === 'cancelled' && $db->value('SELECT 1 FROM attendance WHERE activity_id = ?', [$id])) { throw new ValidationException(['Attendance has been recorded; the activity cannot be cancelled.']); }
            if ($to === 'scheduled' && $a['status'] !== 'cancelled') { throw new ValidationException(['Only a cancelled activity can be rescheduled.']); }
            $db->run('UPDATE activities SET status = ?, updated_at = ? WHERE id = ?', [$to, now_utc(), $id]);
            AuditLogger::record('activity.status_change', 'success', 'activity', $id, ['from' => $a['status'], 'to' => $to]);
        });
    }

    /** IR-02/IR-03: active period containing the activity date; open activity and project. */
    public static function rosterAdd(int $activityId, int $volunteerId): void
    {
        Database::get()->transaction(function (Database $db) use ($activityId, $volunteerId) {
            $a = $db->one('SELECT a.*, p.status AS pstatus FROM activities a JOIN projects p ON p.id = a.project_id WHERE a.id = ? FOR UPDATE', [$activityId]);
            if (!$a) { Gate::notFound('activity'); }
            Scope::assertManageProject((int) $a['project_id']);
            if ($a['status'] !== 'scheduled' || in_array($a['pstatus'], ['completed', 'cancelled'], true)) {
                throw new ValidationException(['Volunteers can only be rostered on scheduled activities of open projects.']);
            }
            $per = ProjectService::periodFor($db, (int) $a['project_id'], $volunteerId, $a['activity_date'], true);
            if (!$per) { throw new ValidationException(['This volunteer has no active service period on this project covering the activity date.']); }
            try {
                $db->insert('activity_assignments', ['activity_id' => $activityId, 'project_id' => (int) $a['project_id'], 'volunteer_id' => $volunteerId,
                    'project_assignment_id' => (int) $per['id'], 'assigned_by' => Auth::id(), 'assigned_at' => now_utc()]);
            } catch (\PDOException $e) {
                if (($e->errorInfo[1] ?? 0) === 1062) { throw new ValidationException(['This volunteer is already on the roster.']); }
                throw $e;
            }
            AuditLogger::record('roster.add', 'success', 'activity', $activityId, ['volunteer' => (string) $volunteerId]);
            $uid = $db->value('SELECT user_id FROM volunteers WHERE id = ?', [$volunteerId]);
            if ($uid) { Notifier::notify((int) $uid, 'roster', 'You have been added to an activity: ' . $a['title'], fmt_date($a['activity_date']) . ' at ' . $a['venue'], '/activities/' . $activityId); }
        });
    }

    public static function rosterRemove(int $activityId, int $volunteerId): void
    {
        Database::get()->transaction(function (Database $db) use ($activityId, $volunteerId) {
            $a = $db->one('SELECT * FROM activities WHERE id = ?', [$activityId]);
            if (!$a) { Gate::notFound('activity'); }
            Scope::assertManageProject((int) $a['project_id']);
            if ($db->value('SELECT 1 FROM attendance WHERE activity_id = ? AND volunteer_id = ?', [$activityId, $volunteerId])
                || $db->value('SELECT 1 FROM time_logs WHERE activity_id = ? AND volunteer_id = ?', [$activityId, $volunteerId])) {
                throw new ValidationException(['Attendance or hours exist for this volunteer, so they cannot be removed from the roster.']);
            }
            $db->run('DELETE FROM activity_assignments WHERE activity_id = ? AND volunteer_id = ?', [$activityId, $volunteerId]);
            AuditLogger::record('roster.remove', 'success', 'activity', $activityId, ['volunteer' => (string) $volunteerId]);
        });
    }

    /** IR-04/05/06: rostered only; present/late not before the date; validity follows the activity date. */
    public static function recordAttendance(int $activityId, array $statuses): int
    {
        return Database::get()->transaction(function (Database $db) use ($activityId, $statuses) {
            $a = $db->one('SELECT * FROM activities WHERE id = ? FOR UPDATE', [$activityId]);
            if (!$a) { Gate::notFound('activity'); }
            Scope::assertManageProject((int) $a['project_id']);
            if ($a['status'] === 'cancelled') { throw new ValidationException(['Attendance cannot be recorded for a cancelled activity.']); }
            $n = 0;
            foreach ($statuses as $vid => $st) {
                $vid = (int) $vid;
                if ($st === '' || $st === null) { continue; }
                if (!in_array($st, ['present', 'late', 'absent', 'excused'], true)) { throw new ValidationException(['Unknown attendance status.']); }
                if (in_array($st, ['present', 'late'], true) && $a['activity_date'] > today_local()) {
                    throw new ValidationException(['Present or late cannot be recorded before the activity date.']);
                }
                $old = $db->one('SELECT id, status FROM attendance WHERE activity_id = ? AND volunteer_id = ?', [$activityId, $vid]);
                if ($old) {
                    if ($old['status'] === $st) { continue; }
                    $db->run('UPDATE attendance SET status = ?, recorded_by = ?, recorded_at = ?, updated_at = ? WHERE id = ?', [$st, Auth::id(), now_utc(), now_utc(), $old['id']]);
                    AuditLogger::record('attendance.correct', 'success', 'attendance', (int) $old['id'], ['from' => $old['status'], 'to' => $st]);
                } else {
                    $id = $db->insert('attendance', ['activity_id' => $activityId, 'volunteer_id' => $vid, 'status' => $st, 'recorded_by' => Auth::id(),
                        'recorded_at' => now_utc(), 'updated_at' => now_utc()]); // FK rejects non-rostered volunteers
                    AuditLogger::record('attendance.record', 'success', 'attendance', $id, ['status' => $st]);
                }
                $n++;
            }
            return $n;
        });
    }
}
