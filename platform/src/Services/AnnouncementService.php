<?php
declare(strict_types=1);

namespace App\Services;

use App\Audit\AuditLogger;
use App\Core\Database;
use App\Core\ValidationException;
use App\Security\Auth;
use App\Security\Gate;

final class AnnouncementService
{
    public static function manageable(): array
    {
        $p = [];
        $sql = 'SELECT a.*, pr.name AS project_name FROM announcements a LEFT JOIN projects pr ON pr.id = a.project_id WHERE a.deleted_at IS NULL';
        if (!Auth::isAdmin()) {
            $f = Scope::supervisorFilter('a.project_id', $p);
            $sql .= " AND a.audience = 'project' AND {$f}";
        }
        return Database::get()->all($sql . ' ORDER BY a.created_at DESC LIMIT 200', $p);
    }

    public static function create(array $in): int
    {
        Gate::authorize('announcements.manage');
        $title = trim((string) ($in['title'] ?? ''));
        $body = trim((string) ($in['body'] ?? ''));
        $aud = (string) ($in['audience'] ?? '');
        $pid = ($in['project_id'] ?? '') !== '' ? (int) $in['project_id'] : null;
        $exp = ($in['expires_on'] ?? '') ?: null;
        $e = [];
        if ($title === '' || mb_strlen($title) > 200) { $e[] = 'Enter a title.'; }
        if ($body === '' || mb_strlen($body) > 5000) { $e[] = 'Enter the announcement text.'; }
        if (!in_array($aud, ['all', 'volunteers', 'supervisors', 'admins', 'project'], true)) { $e[] = 'Choose an audience.'; }
        if ($aud === 'project' && $pid === null) { $e[] = 'Choose the project.'; }
        if ($exp !== null && !ProjectService::isDate($exp)) { $e[] = 'Enter a valid expiry date.'; }
        if (!Auth::isAdmin() && ($aud !== 'project' || $pid === null || !Auth::supervises($pid))) { $e[] = 'Supervisors can post only to projects they supervise.'; }
        if ($e) { throw new ValidationException($e); }
        return Database::get()->transaction(function (Database $db) use ($title, $body, $aud, $pid, $exp, $in) {
            $id = $db->insert('announcements', ['title' => $title, 'body' => $body, 'audience' => $aud, 'project_id' => $aud === 'project' ? $pid : null,
                'published_at' => ($in['publish'] ?? '1') === '1' ? now_utc() : null, 'expires_at' => $exp ? $exp . ' 23:59:59' : null,
                'created_by' => Auth::id(), 'created_at' => now_utc(), 'updated_at' => now_utc()]);
            AuditLogger::record('announcement.create', 'success', 'announcement', $id, ['audience' => $aud]);
            return $id;
        });
    }

    public static function delete(int $id): void
    {
        Database::get()->transaction(function (Database $db) use ($id) {
            $a = $db->one('SELECT * FROM announcements WHERE id = ? AND deleted_at IS NULL', [$id]);
            if (!$a || (!Auth::isAdmin() && ($a['project_id'] === null || !Auth::supervises((int) $a['project_id'])))) { Gate::notFound('announcement'); }
            $db->run('UPDATE announcements SET deleted_at = ? WHERE id = ?', [now_utc(), $id]);
            AuditLogger::record('announcement.delete', 'success', 'announcement', $id);
        });
    }
}
