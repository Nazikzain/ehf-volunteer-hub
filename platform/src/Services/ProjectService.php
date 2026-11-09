<?php
declare(strict_types=1);

namespace App\Services;

use App\Audit\AuditLogger;
use App\Core\Database;
use App\Core\ValidationException;
use App\Security\Auth;
use App\Security\Gate;

/** Projects, supervisors (ended not deleted, §4.3) and service periods (§4.2). */
final class ProjectService
{
    public const STATUSES = ['planned', 'active', 'on_hold', 'completed', 'cancelled'];

    public static function list(array $f): array
    {
        $where = ['p.deleted_at IS NULL'];
        $params = [];
        if ($s = Scope::projectFilter('p.id', $params)) { $where[] = $s; }
        if (!empty($f['status'])) { $where[] = 'p.status = :st'; $params['st'] = $f['status']; }
        if (!empty($f['q'])) { $where[] = 'p.name LIKE :q'; $params['q'] = '%' . $f['q'] . '%'; }
        return Database::get()->all('SELECT p.*,
            (SELECT COUNT(*) FROM project_assignments pa WHERE pa.project_id = p.id AND pa.status = \'active\') AS active_volunteers,
            (SELECT COUNT(*) FROM activities a WHERE a.project_id = p.id) AS activities,
            (SELECT GROUP_CONCAT(u.email SEPARATOR \', \') FROM project_supervisors ps JOIN users u ON u.id = ps.user_id WHERE ps.project_id = p.id AND ps.ended_at IS NULL) AS supervisors
            FROM projects p WHERE ' . implode(' AND ', $where) . ' ORDER BY FIELD(p.status, \'active\', \'planned\', \'on_hold\', \'completed\', \'cancelled\'), p.name', $params);
    }

    public static function get(int $id): array
    {
        Scope::assertViewProject($id);
        $db = Database::get();
        $p = $db->one('SELECT * FROM projects WHERE id = ? AND deleted_at IS NULL', [$id]);
        if (!$p) { Gate::notFound('project'); }
        $p['supervisors'] = $db->all('SELECT ps.*, u.email FROM project_supervisors ps JOIN users u ON u.id = ps.user_id WHERE ps.project_id = ? ORDER BY ps.ended_at IS NULL DESC, u.email', [$id]);
        $periodSql = 'SELECT pa.*, v.full_name FROM project_assignments pa JOIN volunteers v ON v.id = pa.volunteer_id WHERE pa.project_id = ?';
        $args = [$id];
        if (!Auth::isAdmin() && !Auth::supervises($id)) {   // volunteers see only their own periods
            $periodSql .= ' AND pa.volunteer_id = ?';
            $args[] = (int) Auth::volunteerId();
        }
        $p['periods'] = $db->all($periodSql . ' ORDER BY pa.status = \'active\' DESC, v.full_name, pa.start_date DESC', $args);
        $p['activities'] = $db->all('SELECT * FROM activities WHERE project_id = ? ORDER BY activity_date DESC, start_time DESC LIMIT 50', [$id]);
        $p['approved_minutes'] = (int) $db->value("SELECT COALESCE(SUM(minutes), 0) FROM time_logs WHERE project_id = ? AND status = 'approved'", [$id]);
        $p['can_manage'] = Auth::isAdmin() || Auth::supervises($id);
        return $p;
    }

    private static function validate(array $in): array
    {
        $name = trim((string) ($in['name'] ?? ''));
        $status = (string) ($in['status'] ?? 'planned');
        $start = ($in['start_date'] ?? '') ?: null;
        $end = ($in['end_date'] ?? '') ?: null;
        $e = [];
        if ($name === '' || mb_strlen($name) > 150) { $e[] = 'Enter a project name (up to 150 characters).'; }
        if (!in_array($status, self::STATUSES, true)) { $e[] = 'Choose a valid status.'; }
        foreach (['start' => $start, 'end' => $end] as $k => $d) {
            if ($d !== null && !self::isDate($d)) { $e[] = "Enter a valid {$k} date."; }
        }
        if ($start && $end && $end < $start) { $e[] = 'The end date cannot be before the start date.'; }
        if ($e) { throw new ValidationException($e); }
        return ['name' => $name, 'description' => trim((string) ($in['description'] ?? '')) ?: null, 'status' => $status, 'start_date' => $start, 'end_date' => $end];
    }

    public static function isDate(string $d): bool
    {
        $dt = \DateTimeImmutable::createFromFormat('!Y-m-d', $d);
        return $dt !== false && $dt->format('Y-m-d') === $d;
    }

    public static function create(array $in): int
    {
        $d = self::validate($in);
        return Database::get()->transaction(function (Database $db) use ($d) {
            $id = $db->insert('projects', $d + ['created_by' => Auth::id(), 'created_at' => now_utc(), 'updated_at' => now_utc()]);
            AuditLogger::record('project.create', 'success', 'project', $id, ['status' => $d['status']]);
            return $id;
        });
    }

    public static function update(int $id, array $in): void
    {
        $d = self::validate($in);
        Database::get()->transaction(function (Database $db) use ($id, $d) {
            $old = $db->one('SELECT * FROM projects WHERE id = ? AND deleted_at IS NULL FOR UPDATE', [$id]);
            if (!$old) { Gate::notFound('project'); }
            $db->update('projects', $d + ['updated_at' => now_utc()], 'id = :id', ['id' => $id]);
            $changed = array_keys(array_filter($d, fn($v, $k) => $old[$k] != $v, ARRAY_FILTER_USE_BOTH));
            AuditLogger::record($old['status'] !== $d['status'] ? 'project.status_change' : 'project.update', 'success', 'project', $id,
                ['fields' => $changed, 'from' => $old['status'], 'to' => $d['status']]);
        });
    }

    // ---- supervisors -------------------------------------------------------------------------

    public static function assignSupervisor(int $projectId, int $userId): void
    {
        Database::get()->transaction(function (Database $db) use ($projectId, $userId) {
            if (!$db->value('SELECT 1 FROM projects WHERE id = ? AND deleted_at IS NULL', [$projectId])) { Gate::notFound('project'); }
            $ok = $db->value("SELECT 1 FROM users u JOIN user_roles ur ON ur.user_id = u.id AND ur.role_id = 2 WHERE u.id = ? AND u.status IN ('active','invited')", [$userId]);
            if (!$ok) { throw new ValidationException(['Choose a user who holds the Project Supervisor role.']); }
            $row = $db->one('SELECT ended_at FROM project_supervisors WHERE project_id = ? AND user_id = ? FOR UPDATE', [$projectId, $userId]);
            if ($row && $row['ended_at'] === null) { throw new ValidationException(['This person already supervises the project.']); }
            if ($row) {
                $db->run('UPDATE project_supervisors SET ended_at = NULL, ended_by = NULL, assigned_by = ?, assigned_at = ? WHERE project_id = ? AND user_id = ?',
                    [Auth::id(), now_utc(), $projectId, $userId]);
                AuditLogger::record('supervisor.reappoint', 'success', 'project', $projectId, ['user' => (string) $userId]);
            } else {
                $db->insert('project_supervisors', ['project_id' => $projectId, 'user_id' => $userId, 'assigned_by' => Auth::id(), 'assigned_at' => now_utc()]);
                AuditLogger::record('supervisor.assign', 'success', 'project', $projectId, ['user' => (string) $userId]);
            }
            Notifier::notify($userId, 'supervision', 'You have been assigned as a project supervisor', null, '/projects/' . $projectId);
        });
    }

    public static function endSupervisor(int $projectId, int $userId): void
    {
        Database::get()->transaction(function (Database $db) use ($projectId, $userId) {
            $n = $db->run('UPDATE project_supervisors SET ended_at = ?, ended_by = ? WHERE project_id = ? AND user_id = ? AND ended_at IS NULL',
                [now_utc(), Auth::id(), $projectId, $userId])->rowCount();
            if ($n !== 1) { throw new ValidationException(['This person is not an active supervisor of the project.']); }
            AuditLogger::record('supervisor.end', 'success', 'project', $projectId, ['user' => (string) $userId]);
        });
    }

    public static function supervisorCandidates(): array
    {
        return Database::get()->all("SELECT u.id, u.email FROM users u JOIN user_roles ur ON ur.user_id = u.id AND ur.role_id = 2
            WHERE u.status IN ('active','invited') ORDER BY u.email");
    }

    // ---- service periods (IR-14, IR-15) ------------------------------------------------------

    public static function createPeriod(int $projectId, int $volunteerId, string $start, ?string $role): int
    {
        Scope::assertManageProject($projectId);
        if (!self::isDate($start)) { throw new ValidationException(['Enter a valid start date.']); }
        return Database::get()->transaction(function (Database $db) use ($projectId, $volunteerId, $start, $role) {
            $p = $db->one('SELECT status FROM projects WHERE id = ? AND deleted_at IS NULL', [$projectId]);
            if (!$p) { Gate::notFound('project'); }
            if (in_array($p['status'], ['completed', 'cancelled'], true)) { throw new ValidationException(['Volunteers cannot be added to a completed or cancelled project.']); }
            $v = $db->one('SELECT status FROM volunteers WHERE id = ? AND deleted_at IS NULL', [$volunteerId]);
            if (!$v || !in_array($v['status'], ['approved', 'active'], true)) { throw new ValidationException(['Only approved or active volunteers can be assigned.']); }
            $overlap = $db->value('SELECT 1 FROM project_assignments WHERE project_id = ? AND volunteer_id = ? AND (end_date IS NULL OR end_date >= ?) LIMIT 1',
                [$projectId, $volunteerId, $start]);
            if ($overlap) { throw new ValidationException(['This volunteer already has a service period on this project that overlaps the start date.']); }
            try {
                $id = $db->insert('project_assignments', ['project_id' => $projectId, 'volunteer_id' => $volunteerId,
                    'role_description' => $role ? mb_substr($role, 0, 255) : null, 'status' => 'active', 'start_date' => $start,
                    'assigned_by' => Auth::id(), 'assigned_at' => now_utc(), 'updated_at' => now_utc()]);
            } catch (\PDOException $e) {
                if (($e->errorInfo[1] ?? 0) === 1062) { throw new ValidationException(['This volunteer already has an active service period on this project.']); }
                throw $e;
            }
            if ($v['status'] === 'approved') {
                $db->run("UPDATE volunteers SET status = 'active', updated_at = ? WHERE id = ?", [now_utc(), $volunteerId]);
                $db->insert('volunteer_status_history', ['volunteer_id' => $volunteerId, 'from_status' => 'approved', 'to_status' => 'active',
                    'changed_by' => Auth::id(), 'changed_at' => now_utc(), 'reason' => 'First project assignment']);
            }
            AuditLogger::record('period.create', 'success', 'project_assignment', $id, ['project' => (string) $projectId, 'volunteer' => (string) $volunteerId]);
            $uid = $db->value('SELECT user_id FROM volunteers WHERE id = ?', [$volunteerId]);
            if ($uid) { Notifier::notify((int) $uid, 'assignment', 'You have been assigned to a project', null, '/projects/' . $projectId); }
            return $id;
        });
    }

    public static function endPeriod(int $periodId, string $endDate, string $status): void
    {
        if (!self::isDate($endDate)) { throw new ValidationException(['Enter a valid end date.']); }
        if (!in_array($status, ['completed', 'withdrawn'], true)) { throw new ValidationException(['Choose completed or withdrawn.']); }
        Database::get()->transaction(function (Database $db) use ($periodId, $endDate, $status) {
            $pa = $db->one('SELECT * FROM project_assignments WHERE id = ? FOR UPDATE', [$periodId]);
            if (!$pa) { Gate::notFound('period'); }
            Scope::assertManageProject((int) $pa['project_id']);
            if ($pa['status'] !== 'active') { throw new ValidationException(['This service period has already ended.']); }
            if ($endDate < $pa['start_date']) { throw new ValidationException(['The end date cannot be before the start date.']); }
            self::assertNothingOutside($db, $periodId, $pa['start_date'], $endDate);
            $db->run('UPDATE project_assignments SET status = ?, end_date = ?, ended_by = ?, ended_at = ?, updated_at = ? WHERE id = ?',
                [$status, $endDate, Auth::id(), now_utc(), now_utc(), $periodId]);
            AuditLogger::record('period.end', 'success', 'project_assignment', $periodId, ['status' => $status]);
        });
    }

    /** IR-14: date changes may not orphan linked rosters or live time logs. */
    public static function updatePeriodDates(int $periodId, string $start, ?string $end): void
    {
        if (!self::isDate($start) || ($end !== null && !self::isDate($end))) { throw new ValidationException(['Enter valid dates.']); }
        if ($end !== null && $end < $start) { throw new ValidationException(['The end date cannot be before the start date.']); }
        Database::get()->transaction(function (Database $db) use ($periodId, $start, $end) {
            $pa = $db->one('SELECT * FROM project_assignments WHERE id = ? FOR UPDATE', [$periodId]);
            if (!$pa) { Gate::notFound('period'); }
            Scope::assertManageProject((int) $pa['project_id']);
            if ($pa['status'] !== 'active' && $end === null) { throw new ValidationException(['An ended period must keep an end date.']); }
            self::assertNothingOutside($db, $periodId, $start, $end);
            $overlap = $db->value('SELECT 1 FROM project_assignments WHERE project_id = ? AND volunteer_id = ? AND id <> ?
                AND start_date <= COALESCE(?, \'9999-12-31\') AND COALESCE(end_date, \'9999-12-31\') >= ?',
                [$pa['project_id'], $pa['volunteer_id'], $periodId, $end, $start]);
            if ($overlap) { throw new ValidationException(['The new dates would overlap another service period of this volunteer on this project.']); }
            $db->run('UPDATE project_assignments SET start_date = ?, end_date = ?, updated_at = ? WHERE id = ?', [$start, $end, now_utc(), $periodId]);
            AuditLogger::record('period.update_dates', 'success', 'project_assignment', $periodId,
                ['from_start' => $pa['start_date'], 'from_end' => $pa['end_date'], 'to_start' => $start, 'to_end' => $end]);
        });
    }

    private static function assertNothingOutside(Database $db, int $periodId, string $start, ?string $end): void
    {
        $end ??= '9999-12-31';
        $r = (int) $db->value('SELECT COUNT(*) FROM activity_assignments aa JOIN activities a ON a.id = aa.activity_id
            WHERE aa.project_assignment_id = ? AND (a.activity_date < ? OR a.activity_date > ?)', [$periodId, $start, $end]);
        $t = (int) $db->value("SELECT COUNT(*) FROM time_logs WHERE project_assignment_id = ? AND status IN ('pending','revision_requested','approved')
            AND (work_date < ? OR work_date > ?)", [$periodId, $start, $end]);
        if ($r + $t > 0) {
            throw new ValidationException(["{$r} roster entr(ies) and {$t} time log(s) would fall outside these dates. Move or void them first."]);
        }
    }

    /** Active period of a volunteer on a project that contains $date (IR-02, IR-11). */
    public static function periodFor(Database $db, int $projectId, int $volunteerId, string $date, bool $activeOnly): ?array
    {
        return $db->one('SELECT * FROM project_assignments WHERE project_id = ? AND volunteer_id = ? AND start_date <= ?
            AND (end_date IS NULL OR end_date >= ?)' . ($activeOnly ? " AND status = 'active'" : '') . ' ORDER BY start_date DESC LIMIT 1',
            [$projectId, $volunteerId, $date, $date]);
    }
}
