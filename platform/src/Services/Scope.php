<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Security\Auth;
use App\Security\Gate;

/**
 * Data scoping (Design Rev3 §8.1): Admin = All, Supervisor = active supervised projects,
 * Volunteer = own record. Out-of-scope resources produce 404 (via Gate::notFound).
 */
final class Scope
{
    /** SQL fragment restricting a project_id column to the user's scope, or null for "all". */
    public static function projectFilter(string $column, array &$params): ?string
    {
        if (Auth::isAdmin()) {
            return null;
        }
        $ids = Auth::supervisedProjectIds();
        if (Auth::volunteerId() !== null) {
            $ids = array_merge($ids, array_map('intval', array_column(Database::get()->all(
                'SELECT DISTINCT project_id FROM project_assignments WHERE volunteer_id = ?', [Auth::volunteerId()]), 'project_id')));
        }
        $ids = array_values(array_unique($ids));
        if (!$ids) {
            return '1 = 0';
        }
        $ph = [];
        foreach ($ids as $i => $id) {
            $k = 'scope_p' . $i;
            $params[$k] = $id;
            $ph[] = ':' . $k;
        }
        return "{$column} IN (" . implode(',', $ph) . ')';
    }

    /** Supervisor-scope only (operational write access). */
    public static function supervisorFilter(string $column, array &$params): ?string
    {
        if (Auth::isAdmin()) {
            return null;
        }
        $ids = Auth::supervisedProjectIds();
        if (!$ids) {
            return '1 = 0';
        }
        $ph = [];
        foreach ($ids as $i => $id) {
            $params['sup_p' . $i] = $id;
            $ph[] = ':sup_p' . $i;
        }
        return "{$column} IN (" . implode(',', $ph) . ')';
    }

    /** Operational write access to a project: admin, or its active supervisor. */
    public static function assertManageProject(int $projectId): void
    {
        if (Auth::isAdmin()) {
            return;
        }
        if (!Auth::supervises($projectId)) {
            Gate::notFound('project');
        }
    }

    /** Read access to a project: admin, active supervisor, or a volunteer with a period on it. */
    public static function assertViewProject(int $projectId): void
    {
        if (Auth::isAdmin() || Auth::supervises($projectId)) {
            return;
        }
        if (Auth::volunteerId() !== null && Database::get()->value('SELECT 1 FROM project_assignments WHERE project_id = ? AND volunteer_id = ?',
                [$projectId, Auth::volunteerId()])) {
            return;
        }
        Gate::notFound('project');
    }

    /** Read access to a volunteer: admin, self, or active supervisor of a project the volunteer has a period on. */
    public static function assertViewVolunteer(int $volunteerId): void
    {
        if (Auth::isAdmin() || Auth::volunteerId() === $volunteerId) {
            return;
        }
        if (self::supervisesVolunteer($volunteerId)) {
            return;
        }
        Gate::notFound('volunteer');
    }

    public static function supervisesVolunteer(int $volunteerId): bool
    {
        $ids = Auth::supervisedProjectIds();
        if (!$ids) {
            return false;
        }
        $in = implode(',', array_map('intval', $ids));
        return (bool) Database::get()->value("SELECT 1 FROM project_assignments WHERE volunteer_id = ? AND project_id IN ({$in})", [$volunteerId]);
    }
}
