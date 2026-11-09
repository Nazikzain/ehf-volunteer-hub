<?php
declare(strict_types=1);

namespace App\Services;

use App\Audit\AuditLogger;
use App\Core\Database;
use App\Core\ValidationException;
use App\Security\Auth;
use App\Security\Gate;

/** Volunteer notes (C3). Volunteers never see notes (D-12 conservative default). */
final class NoteService
{
    public static function forVolunteer(int $volunteerId): array
    {
        if (!Gate::allows('notes.view')) {
            return [];
        }
        $sql = 'SELECT n.*, u.email AS author_email, p.name AS project_name FROM volunteer_notes n JOIN users u ON u.id = n.author_user_id
            LEFT JOIN projects p ON p.id = n.project_id WHERE n.volunteer_id = :v AND n.deleted_at IS NULL';
        $params = ['v' => $volunteerId];
        if (!Auth::isAdmin()) {
            $f = Scope::supervisorFilter('n.project_id', $params);
            $sql .= " AND n.visibility = 'project' AND {$f}";
        }
        return Database::get()->all($sql . ' ORDER BY n.created_at DESC', $params);
    }

    public static function create(int $volunteerId, string $body, string $visibility, ?int $projectId): int
    {
        Gate::authorize('notes.write');
        Scope::assertViewVolunteer($volunteerId);
        $body = trim($body);
        if ($body === '' || mb_strlen($body) > 5000) { throw new ValidationException(['Enter a note (up to 5000 characters).']); }
        if (!Auth::isAdmin()) {
            $visibility = 'project';
            if ($projectId === null || !Auth::supervises($projectId)) { throw new ValidationException(['Choose one of your projects for this note.']); }
        }
        if (!in_array($visibility, ['admins', 'project'], true)) { throw new ValidationException(['Choose who can see this note.']); }
        if ($visibility === 'project' && $projectId === null) { throw new ValidationException(['Choose the project for a project note.']); }
        return Database::get()->transaction(function (Database $db) use ($volunteerId, $body, $visibility, $projectId) {
            $id = $db->insert('volunteer_notes', ['volunteer_id' => $volunteerId, 'author_user_id' => Auth::id(),
                'project_id' => $visibility === 'project' ? $projectId : null, 'visibility' => $visibility, 'body' => $body,
                'created_at' => now_utc(), 'updated_at' => now_utc()]);
            AuditLogger::record('note.create', 'success', 'volunteer_note', $id, ['volunteer' => (string) $volunteerId, 'visibility' => $visibility]);
            return $id;
        });
    }

    public static function delete(int $noteId): int
    {
        return Database::get()->transaction(function (Database $db) use ($noteId) {
            $n = $db->one('SELECT * FROM volunteer_notes WHERE id = ? AND deleted_at IS NULL FOR UPDATE', [$noteId]);
            if (!$n || (!Auth::isAdmin() && (int) $n['author_user_id'] !== Auth::id())) { Gate::notFound('note'); }
            $db->run('UPDATE volunteer_notes SET deleted_at = ? WHERE id = ?', [now_utc(), $noteId]);
            AuditLogger::record('note.delete', 'success', 'volunteer_note', $noteId);
            return (int) $n['volunteer_id'];
        });
    }
}
