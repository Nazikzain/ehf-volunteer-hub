<?php
declare(strict_types=1);

/*
 * Permission map (Design Rev3 §8.1, D-21). Version-controlled; there is deliberately no screen to edit it.
 * The Primary Admin (system_owner) holds every permission regardless of this map.
 * 'owner' => permissions reserved to the Primary Admin only.
 * Scope (All / Assigned / Own) is enforced in the services' data access, not here.
 */
const OA = 'organisation_admin';
const PS = 'project_supervisor';
const VO = 'volunteer';

return [
    'dashboard.admin' => [OA],
    'dashboard.supervisor' => [PS],
    'dashboard.volunteer' => [VO],
    'applications.view' => [OA],
    'applications.review' => [OA],
    'volunteers.view' => [OA, PS],
    'volunteers.edit' => [OA],
    'volunteers.self' => [VO],
    'notes.view' => [OA, PS],
    'notes.write' => [OA, PS],
    'projects.view' => [OA, PS, VO],
    'projects.manage' => [OA],
    'supervisors.manage' => [OA],
    'periods.manage' => [OA, PS],
    'activities.view' => [OA, PS, VO],
    'activities.manage' => [OA, PS],
    'attendance.manage' => [OA, PS],
    'tasks.view' => [OA, PS, VO],
    'tasks.manage' => [OA, PS],
    'timelogs.submit' => [VO],
    'timelogs.review' => [OA, PS],
    'reviews.write' => [OA, PS],
    'reviews.view' => [OA, PS, VO],
    'training.manage' => [OA],
    'training.view' => [OA, PS, VO],
    'training_types.manage' => [OA],
    'certificates.manage' => [OA],
    'certificates.view' => [OA, VO],
    'announcements.manage' => [OA, PS],
    'announcements.view' => [OA, PS, VO],
    'reports.view' => [OA, PS],
    'reports.export' => [OA, PS],
    'users.manage' => [OA],
    'roles.manage' => [OA],
    'audit.view' => [OA],
    'audit.reconcile' => [OA],
    'erasure.prepare' => [OA],
    'settings.manage' => [OA],
    'notifications.view' => [OA, PS, VO],
    'owner.transfer' => 'owner',
    'audit.archive' => 'owner',
    'erasure.approve' => 'owner',
];
