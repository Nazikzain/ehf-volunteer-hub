<?php
declare(strict_types=1);

namespace App\Services;

use App\Audit\AuditLogger;
use App\Core\Database;
use App\Core\ValidationException;
use App\Security\Auth;
use App\Security\Gate;

final class TaskService
{
    public const STATUSES = ['pending', 'in_progress', 'completed', 'cancelled'];

    public static function list(array $f): array
    {
        $params = [];
        $where = ['t.deleted_at IS NULL'];
        if (Auth::isAdmin() || Auth::supervisedProjectIds()) {
            if ($s = Scope::projectFilter('t.project_id', $params)) { $where[] = $s; }
        } else {
            $where[] = 'EXISTS (SELECT 1 FROM project_assignments pa WHERE pa.id = t.assigned_assignment_id AND pa.volunteer_id = :me)';
            $params['me'] = (int) Auth::volunteerId();
        }
        if (!empty($f['project'])) { $where[] = 't.project_id = :p'; $params['p'] = (int) $f['project']; }
        if (!empty($f['status'])) { $where[] = 't.status = :st'; $params['st'] = $f['status']; }
        return Database::get()->all('SELECT t.*, p.name AS project_name, v.full_name AS assignee FROM tasks t JOIN projects p ON p.id = t.project_id
            LEFT JOIN project_assignments pa ON pa.id = t.assigned_assignment_id LEFT JOIN volunteers v ON v.id = pa.volunteer_id
            WHERE ' . implode(' AND ', $where) . ' ORDER BY FIELD(t.status, \'in_progress\', \'pending\', \'completed\', \'cancelled\'), t.due_date IS NULL, t.due_date LIMIT 500', $params);
    }

    /** Active periods on a project, for the assignee picker (IR-16). */
    public static function assignable(int $projectId): array
    {
        return Database::get()->all("SELECT pa.id, v.full_name FROM project_assignments pa JOIN volunteers v ON v.id = pa.volunteer_id
            WHERE pa.project_id = ? AND pa.status = 'active' ORDER BY v.full_name", [$projectId]);
    }

    public static function save(?int $id, int $projectId, array $in): int
    {
        Gate::authorize('tasks.manage');
        Scope::assertManageProject($projectId);
        $title = trim((string) ($in['title'] ?? ''));
        $status = (string) ($in['status'] ?? 'pending');
        $due = ($in['due_date'] ?? '') ?: null;
        $assign = ($in['assigned_assignment_id'] ?? '') !== '' ? (int) $in['assigned_assignment_id'] : null;
        $e = [];
        if ($title === '' || mb_strlen($title) > 180) { $e[] = 'Enter a task title (up to 180 characters).'; }
        if (!in_array($status, self::STATUSES, true)) { $e[] = 'Choose a valid status.'; }
        if ($due !== null && !ProjectService::isDate($due)) { $e[] = 'Enter a valid due date.'; }
        if ($e) { throw new ValidationException($e); }
        return Database::get()->transaction(function (Database $db) use ($id, $projectId, $title, $status, $due, $assign, $in) {
            if ($assign !== null && !$db->value("SELECT 1 FROM project_assignments WHERE id = ? AND project_id = ? AND status = 'active'", [$assign, $projectId])) {
                throw new ValidationException(['The assignee must have an active service period on this project.']);
            }
            $data = ['title' => $title, 'description' => trim((string) ($in['description'] ?? '')) ?: null, 'status' => $status, 'due_date' => $due,
                'assigned_assignment_id' => $assign, 'completed_at' => $status === 'completed' ? now_utc() : null, 'updated_at' => now_utc()];
            if ($id === null) {
                $id = $db->insert('tasks', $data + ['project_id' => $projectId, 'created_by' => Auth::id(), 'created_at' => now_utc()]);
                AuditLogger::record('task.create', 'success', 'task', $id, ['project' => (string) $projectId]);
            } else {
                $old = $db->one('SELECT * FROM tasks WHERE id = ? AND project_id = ? AND deleted_at IS NULL FOR UPDATE', [$id, $projectId]);
                if (!$old) { Gate::notFound('task'); }
                if ($old['status'] === 'completed' && $status === 'completed') { $data['completed_at'] = $old['completed_at']; }
                $db->update('tasks', $data, 'id = :id', ['id' => $id]);
                AuditLogger::record($old['status'] !== $status ? 'task.status_change' : 'task.update', 'success', 'task', $id, ['from' => $old['status'], 'to' => $status]);
            }
            if ($assign !== null) {
                $uid = $db->value('SELECT v.user_id FROM project_assignments pa JOIN volunteers v ON v.id = pa.volunteer_id WHERE pa.id = ?', [$assign]);
                if ($uid) { Notifier::notify((int) $uid, 'task', 'A task has been assigned to you: ' . $title, null, '/tasks'); }
            }
            return $id;
        });
    }
}
