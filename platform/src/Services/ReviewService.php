<?php
declare(strict_types=1);

namespace App\Services;

use App\Audit\AuditLogger;
use App\Core\Database;
use App\Core\ValidationException;
use App\Security\Auth;
use App\Security\Gate;

/** Performance reviews. Volunteers see own reviews only when shared (D-11 conservative default: not shared). */
final class ReviewService
{
    public static function forVolunteer(int $vid): array
    {
        $db = Database::get();
        $sql = 'SELECT r.*, u.email AS reviewer_email, p.name AS project_name FROM performance_reviews r JOIN users u ON u.id = r.reviewer_id
            LEFT JOIN projects p ON p.id = r.project_id WHERE r.volunteer_id = :v AND r.deleted_at IS NULL';
        $params = ['v' => $vid];
        if (Auth::isAdmin()) {
            // all
        } elseif (Auth::volunteerId() === $vid && !Auth::supervisedProjectIds()) {
            $sql .= ' AND r.shared_with_volunteer = 1';
        } else {
            $f = Scope::supervisorFilter('r.project_id', $params);
            $sql .= " AND {$f}";
        }
        return $db->all($sql . ' ORDER BY r.review_date DESC', $params);
    }

    public static function create(int $vid, array $in): int
    {
        Gate::authorize('reviews.write');
        Scope::assertViewVolunteer($vid);
        $projectId = ($in['project_id'] ?? '') !== '' ? (int) $in['project_id'] : null;
        $scores = [];
        foreach (['reliability', 'teamwork', 'communication', 'quality'] as $k) {
            $s = (int) ($in[$k] ?? 0);
            if ($s < 1 || $s > 5) { throw new ValidationException(['Each score must be between 1 and 5.']); }
            $scores[$k] = $s;
        }
        $date = (string) ($in['review_date'] ?? today_local());
        if (!ProjectService::isDate($date) || $date > today_local()) { throw new ValidationException(['Enter a valid review date (not in the future).']); }
        return Database::get()->transaction(function (Database $db) use ($vid, $projectId, $scores, $date, $in) {
            if (!Auth::isAdmin()) {
                if ($projectId === null || !Auth::supervises($projectId)
                    || !$db->value('SELECT 1 FROM project_assignments WHERE project_id = ? AND volunteer_id = ?', [$projectId, $vid])) {
                    throw new ValidationException(['Choose one of your projects on which this volunteer serves.']);
                }
            }
            $id = $db->insert('performance_reviews', $scores + ['volunteer_id' => $vid, 'project_id' => $projectId, 'reviewer_id' => Auth::id(),
                'review_date' => $date, 'achievements' => trim((string) ($in['achievements'] ?? '')) ?: null,
                'improvement_areas' => trim((string) ($in['improvement_areas'] ?? '')) ?: null, 'notes' => trim((string) ($in['notes'] ?? '')) ?: null,
                'shared_with_volunteer' => ($in['shared'] ?? '') === '1' ? 1 : 0, 'created_at' => now_utc(), 'updated_at' => now_utc()]);
            AuditLogger::record('review.create', 'success', 'performance_review', $id, ['volunteer' => (string) $vid]);
            return $id;
        });
    }

    public static function delete(int $id): int
    {
        return Database::get()->transaction(function (Database $db) use ($id) {
            $r = $db->one('SELECT * FROM performance_reviews WHERE id = ? AND deleted_at IS NULL FOR UPDATE', [$id]);
            if (!$r) { Gate::notFound('review'); }
            if (!Auth::isAdmin() && (int) $r['reviewer_id'] !== Auth::id()) { Gate::notFound('review'); }
            $db->run('UPDATE performance_reviews SET deleted_at = ? WHERE id = ?', [now_utc(), $id]); // soft delete only
            AuditLogger::record('review.delete', 'success', 'performance_review', $id);
            return (int) $r['volunteer_id'];
        });
    }
}
