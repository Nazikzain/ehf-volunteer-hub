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
$r->get('/volunteers/{id}', [C\VolunteerController::class, 'show'], ['perm' => 'volunteers.view']);
$r->post('/volunteers/{id}', [C\VolunteerController::class, 'update'], ['perm' => 'volunteers.edit']);
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

return $r;
