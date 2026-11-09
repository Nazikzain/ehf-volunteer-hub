<?php
declare(strict_types=1);

use App\Controllers as C;
use App\Core\Router;

$r = new Router();
$pub = ['auth' => false];

// Public
$r->get('/', [C\AuthController::class, 'home'], $pub);
$r->get('/apply', [C\PublicController::class, 'applyForm'], $pub);
$r->post('/apply', [C\PublicController::class, 'apply'], $pub);
$r->get('/apply/thank-you', [C\PublicController::class, 'thanks'], $pub);
$r->get('/login', [C\AuthController::class, 'loginForm'], $pub);
$r->post('/login', [C\AuthController::class, 'login'], $pub);
$r->post('/logout', [C\AuthController::class, 'logout']);
$r->get('/activate', [C\AuthController::class, 'activateForm'], $pub);
$r->post('/activate', [C\AuthController::class, 'activate'], $pub);
$r->get('/forgot-password', [C\AuthController::class, 'forgotForm'], $pub);
$r->post('/forgot-password', [C\AuthController::class, 'forgot'], $pub);
$r->get('/reset-password', [C\AuthController::class, 'resetForm'], $pub);
$r->post('/reset-password', [C\AuthController::class, 'reset'], $pub);

// Everyone signed in
$r->get('/dashboard', [C\DashboardController::class, 'index'], ['perm' => 'notifications.view']);
$r->get('/notifications', [C\NotificationController::class, 'index'], ['perm' => 'notifications.view']);
$r->post('/notifications/read', [C\NotificationController::class, 'read'], ['perm' => 'notifications.view']);

// Recruitment
$r->get('/admin/applications', [C\ApplicationController::class, 'index'], ['perm' => 'applications.view']);
$r->get('/admin/applications/{id}', [C\ApplicationController::class, 'show'], ['perm' => 'applications.view']);
$r->post('/admin/applications/{id}/status', [C\ApplicationController::class, 'status'], ['perm' => 'applications.review']);

// Users
$r->get('/admin/users', [C\UserController::class, 'index'], ['perm' => 'users.manage']);
$r->post('/admin/users', [C\UserController::class, 'invite'], ['perm' => 'users.manage']);
$r->get('/admin/users/{id}', [C\UserController::class, 'show'], ['perm' => 'users.manage']);
$r->post('/admin/users/{id}', [C\UserController::class, 'action'], ['perm' => 'users.manage']);
$r->post('/admin/ownership', [C\UserController::class, 'transfer'], ['perm' => 'owner.transfer']);

// Volunteers
$r->get('/volunteers', [C\VolunteerController::class, 'index'], ['perm' => 'volunteers.view']);
$r->get('/volunteers/{id}', [C\VolunteerController::class, 'show']); // scope in controller (ST-04)
$r->post('/volunteers/{id}', [C\VolunteerController::class, 'update']); // scope in controller (ST-05/06)
$r->post('/volunteers/{id}/notes', [C\VolunteerController::class, 'addNote'], ['perm' => 'notes.write']);
$r->post('/notes/{id}/delete', [C\VolunteerController::class, 'deleteNote'], ['perm' => 'notes.write']);
$r->post('/volunteers/{id}/erasure', [C\VolunteerController::class, 'erasure'], ['perm' => 'erasure.prepare']);
$r->get('/me', [C\VolunteerController::class, 'me'], ['perm' => 'volunteers.self']);
$r->post('/me', [C\VolunteerController::class, 'meUpdate'], ['perm' => 'volunteers.self']);

// Projects
$r->get('/projects', [C\ProjectController::class, 'index'], ['perm' => 'projects.view']);
$r->post('/projects', [C\ProjectController::class, 'create'], ['perm' => 'projects.manage']);
$r->get('/projects/{id}', [C\ProjectController::class, 'show'], ['perm' => 'projects.view']);
$r->post('/projects/{id}', [C\ProjectController::class, 'update'], ['perm' => 'projects.manage']);
$r->post('/projects/{id}/supervisors', [C\ProjectController::class, 'supervisors'], ['perm' => 'supervisors.manage']);
$r->post('/projects/{id}/periods', [C\ProjectController::class, 'periods'], ['perm' => 'periods.manage']);

// Activities, rosters, attendance
$r->get('/activities', [C\ActivityController::class, 'index'], ['perm' => 'activities.view']);
$r->post('/activities', [C\ActivityController::class, 'create'], ['perm' => 'activities.manage']);
$r->get('/activities/{id}', [C\ActivityController::class, 'show'], ['perm' => 'activities.view']);
$r->post('/activities/{id}', [C\ActivityController::class, 'update'], ['perm' => 'activities.manage']);

// Tasks
$r->get('/tasks', [C\TaskController::class, 'index'], ['perm' => 'tasks.view']);
$r->post('/tasks', [C\TaskController::class, 'save'], ['perm' => 'tasks.manage']);

// Hours
$r->get('/my/hours', [C\HoursController::class, 'mine'], ['perm' => 'timelogs.submit']);
$r->post('/my/hours', [C\HoursController::class, 'submit'], ['perm' => 'timelogs.submit']);
$r->post('/my/hours/{id}', [C\HoursController::class, 'edit'], ['perm' => 'timelogs.submit']);
$r->get('/hours/review', [C\HoursController::class, 'queue'], ['perm' => 'timelogs.review']);
$r->post('/hours/{id}/review', [C\HoursController::class, 'review'], ['perm' => 'timelogs.review']);

// Reviews, training, certificates
$r->get('/volunteers/{id}/reviews', [C\DevelopmentController::class, 'reviews']); // scope in controller (ST-22)
$r->post('/volunteers/{id}/reviews', [C\DevelopmentController::class, 'addReview'], ['perm' => 'reviews.write']);
$r->post('/reviews/{id}/delete', [C\DevelopmentController::class, 'deleteReview'], ['perm' => 'reviews.write']);
$r->get('/my/reviews', [C\DevelopmentController::class, 'myReviews'], ['perm' => 'volunteers.self']);
$r->get('/admin/training-types', [C\DevelopmentController::class, 'types'], ['perm' => 'training_types.manage']);
$r->post('/admin/training-types', [C\DevelopmentController::class, 'saveType'], ['perm' => 'training_types.manage']);
$r->post('/volunteers/{id}/training', [C\DevelopmentController::class, 'recordTraining'], ['perm' => 'training.manage']);
$r->get('/certificates', [C\DevelopmentController::class, 'certificates'], ['perm' => 'certificates.view']);
$r->post('/certificates', [C\DevelopmentController::class, 'createCertificate'], ['perm' => 'certificates.manage']);
$r->post('/certificates/{id}', [C\DevelopmentController::class, 'advanceCertificate'], ['perm' => 'certificates.manage']);
$r->get('/certificates/{id}', [C\DevelopmentController::class, 'printCertificate'], ['perm' => 'certificates.view']);

// Communication
$r->get('/announcements', [C\AdminController::class, 'announcements'], ['perm' => 'announcements.view']);
$r->post('/announcements', [C\AdminController::class, 'createAnnouncement'], ['perm' => 'announcements.manage']);
$r->post('/announcements/{id}/delete', [C\AdminController::class, 'deleteAnnouncement'], ['perm' => 'announcements.manage']);

// Reports
$r->get('/reports', [C\AdminController::class, 'reports'], ['perm' => 'reports.view']);
$r->get('/reports/export', [C\AdminController::class, 'export'], ['perm' => 'reports.export']);

// Administration
$r->get('/admin/audit', [C\AdminController::class, 'audit'], ['perm' => 'audit.view']);
$r->post('/admin/audit/verify', [C\AdminController::class, 'auditVerify'], ['perm' => 'audit.view']);
$r->post('/admin/audit/reconcile', [C\AdminController::class, 'auditReconcile'], ['perm' => 'audit.reconcile']);
$r->get('/admin/integrity', [C\AdminController::class, 'integrity'], ['perm' => 'audit.view']);
$r->get('/admin/outbox', [C\AdminController::class, 'outbox'], ['perm' => 'settings.manage']);
$r->post('/admin/outbox', [C\AdminController::class, 'processOutbox'], ['perm' => 'settings.manage']);
$r->get('/admin/settings', [C\AdminController::class, 'settings'], ['perm' => 'settings.manage']);
$r->post('/admin/settings', [C\AdminController::class, 'saveSettings'], ['perm' => 'settings.manage']);

return $r;
