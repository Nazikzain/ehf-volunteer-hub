<?php
declare(strict_types=1);

namespace App\Services;

use App\Audit\AuditLogger;
use App\Core\Database;
use App\Core\ValidationException;
use App\Security\Auth;
use App\Security\Gate;

/** Hours (Design Rev3 §4.4). Approved logs are the only source of hours KPIs. */
final class TimeLogService
{
    public const LIVE = ['pending', 'revision_requested', 'approved'];

    /** What the volunteer can log against: active or recently ended periods, their rostered activities and open tasks. */
    public static function options(int $volunteerId): array
    {
        $db = Database::get();
        $late = (int) Settings::get('timelogs.late_submission_days', '30');
        $periods = $db->all("SELECT pa.*, p.name AS project_name FROM project_assignments pa JOIN projects p ON p.id = pa.project_id
            WHERE pa.volunteer_id = ? AND (pa.status = 'active' OR pa.end_date >= DATE_SUB(?, INTERVAL {$late} DAY)) ORDER BY p.name", [$volunteerId, today_local()]);
        $activities = $db->all("SELECT a.id, a.title, a.activity_date, a.project_id, p.name AS project_name FROM activity_assignments aa
            JOIN activities a ON a.id = aa.activity_id JOIN projects p ON p.id = a.project_id
            WHERE aa.volunteer_id = ? AND a.status <> 'cancelled' AND a.activity_date <= ?
            AND NOT EXISTS (SELECT 1 FROM time_logs t WHERE t.volunteer_id = aa.volunteer_id AND t.live_activity_id = a.id)
            ORDER BY a.activity_date DESC LIMIT 50", [$volunteerId, today_local()]);
        $pids = array_map('intval', array_column($periods, 'project_id')) ?: [0];
        $tasks = $db->all('SELECT t.id, t.title, t.project_id FROM tasks t WHERE t.deleted_at IS NULL AND t.status IN (\'pending\',\'in_progress\')
            AND t.project_id IN (' . implode(',', $pids) . ') ORDER BY t.title');
        return compact('periods', 'activities', 'tasks');
    }

    public static function mine(int $volunteerId): array
    {
        return Database::get()->all('SELECT t.*, p.name AS project_name, a.title AS activity_title, k.title AS task_title FROM time_logs t
            JOIN projects p ON p.id = t.project_id LEFT JOIN activities a ON a.id = t.activity_id LEFT JOIN tasks k ON k.id = t.task_id
            WHERE t.volunteer_id = ? ORDER BY t.work_date DESC, t.id DESC LIMIT 200', [$volunteerId]);
    }

    /**
     * Submit (volunteer). $kind = activity|task|general. Returns [id, warnings].
     */
    public static function submit(array $in): array
    {
        $vid = Auth::volunteerId();
        if ($vid === null) { Gate::deny(403, ['reason' => 'not_a_volunteer']); } // ST-15
        $kind = (string) ($in['kind'] ?? 'general');
        $minutes = (int) round(((float) ($in['hours'] ?? 0)) * 60);
        $desc = trim((string) ($in['description'] ?? ''));
        $e = [];
        if ($minutes < 1 || $minutes > 1440) { $e[] = 'Enter the hours worked (more than 0, at most 24).'; }
        if ($desc === '' || mb_strlen($desc) > 500) { $e[] = 'Describe what you did (up to 500 characters).'; }
        if ($e) { throw new ValidationException($e); }
        $resub = ($in['resubmission_of_id'] ?? '') !== '' ? (int) $in['resubmission_of_id'] : null;
        return Database::get()->transaction(function (Database $db) use ($vid, $kind, $minutes, $desc, $in, $resub) {
            $activityId = null;
            $taskId = null;
            if ($kind === 'activity') {
                $activityId = (int) ($in['activity_id'] ?? 0);
                $a = $db->one('SELECT a.* FROM activities a JOIN activity_assignments aa ON aa.activity_id = a.id AND aa.volunteer_id = ? WHERE a.id = ?', [$vid, $activityId]);
                if (!$a) { throw new ValidationException(['Choose an activity you were rostered on.']); }
                if ($a['status'] === 'cancelled') { throw new ValidationException(['Hours cannot be logged against a cancelled activity.']); }
                $projectId = (int) $a['project_id'];
                $workDate = $a['activity_date']; // IR-11
            } else {
                $projectId = (int) ($in['project_id'] ?? 0);
                $workDate = (string) ($in['work_date'] ?? '');
                if (!ProjectService::isDate($workDate)) { throw new ValidationException(['Enter the date of the work.']); }
                if ($kind === 'task') {
                    $taskId = (int) ($in['task_id'] ?? 0);
                    $t = $db->one('SELECT * FROM tasks WHERE id = ? AND deleted_at IS NULL', [$taskId]);
                    if (!$t || $t['status'] === 'cancelled') { throw new ValidationException(['Choose an open task.']); }
                    $projectId = (int) $t['project_id']; // D-18 default: any volunteer with an active period on the project
                }
            }
            if ($workDate > today_local()) { throw new ValidationException(['Hours cannot be logged for a future date.']); }
            $per = ProjectService::periodFor($db, $projectId, $vid, $workDate, false);
            if (!$per) { throw new ValidationException(['You were not in service on this project on that date.']); }
            if ($per['status'] !== 'active') { // IR-12 late submission window (D-17 default 30 days)
                $late = (int) Settings::get('timelogs.late_submission_days', '30');
                if ($per['end_date'] < (new \DateTimeImmutable(today_local()))->modify("-{$late} days")->format('Y-m-d')) {
                    throw new ValidationException(["Hours for a completed service period must be submitted within {$late} days of its end."]);
                }
            }
            $dayTotal = (int) $db->value("SELECT COALESCE(SUM(minutes), 0) FROM time_logs WHERE volunteer_id = ? AND work_date = ? AND status IN ('pending','revision_requested','approved')", [$vid, $workDate]);
            if ($dayTotal + $minutes > 1440) { throw new ValidationException(['The total hours logged for that day would exceed 24.']); }
            $warnings = [];
            if ($kind === 'general' && $db->value("SELECT 1 FROM time_logs WHERE volunteer_id = ? AND project_id = ? AND work_date = ? AND activity_id IS NULL AND task_id IS NULL
                AND status IN ('pending','revision_requested','approved')", [$vid, $projectId, $workDate])) {
                if (Settings::get('timelogs.general_duplicate_policy', 'warn') === 'block') {
                    throw new ValidationException(['You have already logged general work on this project for that date.']);
                }
                $warnings[] = 'You already logged general work on this project for that date. Please check this is not a duplicate.';
            }
            if ($resub !== null && !$db->value("SELECT 1 FROM time_logs WHERE id = ? AND volunteer_id = ? AND status IN ('rejected','voided')", [$resub, $vid])) {
                throw new ValidationException(['Only a rejected or voided log can be resubmitted.']);
            }
            try {
                $id = $db->insert('time_logs', ['volunteer_id' => $vid, 'project_id' => $projectId, 'project_assignment_id' => (int) $per['id'],
                    'activity_id' => $activityId, 'task_id' => $taskId, 'work_date' => $workDate, 'minutes' => $minutes, 'description' => $desc,
                    'status' => 'pending', 'resubmission_of_id' => $resub, 'submitted_by' => Auth::id(), 'submitted_at' => now_utc(),
                    'created_at' => now_utc(), 'updated_at' => now_utc()]);
            } catch (\PDOException $ex) {
                if (($ex->errorInfo[1] ?? 0) === 1062) {
                    throw new ValidationException([$activityId ? 'You have already logged hours for this activity.' : 'You have already logged hours for this task on that date, or this log was already resubmitted.']);
                }
                throw $ex;
            }
            AuditLogger::record('timelog.submit', 'success', 'time_log', $id, ['minutes' => $minutes, 'kind' => $kind]);
            return [$id, $warnings];
        });
    }

    /** Volunteer edits own log while pending or revision requested (returns to pending). */
    public static function edit(int $id, array $in): void
    {
        $minutes = (int) round(((float) ($in['hours'] ?? 0)) * 60);
        $desc = trim((string) ($in['description'] ?? ''));
        if ($minutes < 1 || $minutes > 1440 || $desc === '' || mb_strlen($desc) > 500) { throw new ValidationException(['Enter valid hours and a description.']); }
        Database::get()->transaction(function (Database $db) use ($id, $minutes, $desc) {
            $t = $db->one('SELECT * FROM time_logs WHERE id = ? FOR UPDATE', [$id]);
            if (!$t || (int) $t['volunteer_id'] !== Auth::volunteerId()) { Gate::notFound('time_log'); }
            if (!in_array($t['status'], ['pending', 'revision_requested'], true)) { throw new ValidationException(['Only pending logs or logs returned for revision can be edited.']); }
            $dayTotal = (int) $db->value("SELECT COALESCE(SUM(minutes), 0) FROM time_logs WHERE volunteer_id = ? AND work_date = ? AND id <> ? AND status IN ('pending','revision_requested','approved')",
                [$t['volunteer_id'], $t['work_date'], $id]);
            if ($dayTotal + $minutes > 1440) { throw new ValidationException(['The total hours logged for that day would exceed 24.']); }
            $db->run("UPDATE time_logs SET minutes = ?, description = ?, status = 'pending', reviewed_by = NULL, reviewed_at = NULL, updated_at = ? WHERE id = ?",
                [$minutes, $desc, now_utc(), $id]);
            AuditLogger::record('timelog.edit', 'success', 'time_log', $id, ['fields' => ['minutes', 'description']]);
        });
    }

    public static function reviewQueue(array $f): array
    {
        $params = [];
        $where = ['1=1'];
        if ($s = Scope::supervisorFilter('t.project_id', $params)) { $where[] = $s; }
        $where[] = 't.status = :st';
        $params['st'] = $f['status'] ?? 'pending';
        if (!empty($f['project'])) { $where[] = 't.project_id = :p'; $params['p'] = (int) $f['project']; }
        return Database::get()->all('SELECT t.*, v.full_name, p.name AS project_name, a.title AS activity_title, k.title AS task_title FROM time_logs t
            JOIN volunteers v ON v.id = t.volunteer_id JOIN projects p ON p.id = t.project_id LEFT JOIN activities a ON a.id = t.activity_id
            LEFT JOIN tasks k ON k.id = t.task_id WHERE ' . implode(' AND ', $where) . ' ORDER BY t.work_date, t.id LIMIT 300', $params);
    }

    /** approve | reject | revision_requested | voided */
    public static function review(int $id, string $decision, string $comment): void
    {
        $map = ['approve' => 'approved', 'reject' => 'rejected', 'revision' => 'revision_requested', 'void' => 'voided'];
        if (!isset($map[$decision])) { throw new ValidationException(['Unknown decision.']); }
        $to = $map[$decision];
        $comment = trim($comment);
        Database::get()->transaction(function (Database $db) use ($id, $to, $comment, $decision) {
            $t = $db->one('SELECT * FROM time_logs WHERE id = ? FOR UPDATE', [$id]);
            if (!$t) { Gate::notFound('time_log'); }
            Scope::assertManageProject((int) $t['project_id']);
            if ((int) $t['submitted_by'] === Auth::id()) { Gate::deny(403, ['reason' => 'self_approval']); }
            $now = now_utc();
            if ($to === 'voided') {
                if ($t['status'] !== 'approved') { throw new ValidationException(['Only approved hours can be voided.']); }
                if ($comment === '') { throw new ValidationException(['Give a reason for voiding these hours.']); }
                $db->run("UPDATE time_logs SET status = 'voided', voided_by = ?, voided_at = ?, void_reason = ?, updated_at = ? WHERE id = ?",
                    [Auth::id(), $now, mb_substr($comment, 0, 255), $now, $id]);
            } else {
                if ($t['status'] !== 'pending') { throw new ValidationException(['Only pending hours can be reviewed.']); }
                if ($to !== 'approved' && $comment === '') { throw new ValidationException(['Add a comment for the volunteer.']); }
                $db->run('UPDATE time_logs SET status = ?, reviewed_by = ?, reviewed_at = ?, reviewer_comment = ?, updated_at = ? WHERE id = ?',
                    [$to, Auth::id(), $now, $comment !== '' ? mb_substr($comment, 0, 500) : null, $now, $id]);
            }
            AuditLogger::record(['approve' => 'timelog.approve', 'reject' => 'timelog.reject', 'revision' => 'timelog.revision_request', 'void' => 'timelog.void'][$decision],
                'success', 'time_log', $id, ['from' => $t['status'], 'to' => $to]);
            $uid = $db->value('SELECT user_id FROM volunteers WHERE id = ?', [$t['volunteer_id']]);
            if ($uid) { Notifier::notify((int) $uid, 'hours', 'Your hours for ' . fmt_date($t['work_date']) . ' were ' . label($to), $comment ?: null, '/my/hours'); }
        });
    }
}
