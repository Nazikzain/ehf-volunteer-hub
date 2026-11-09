<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Security\Auth;
use App\Services\ProjectService;
use App\Services\TaskService;

final class TaskController extends Controller
{
    public function index(Request $r): Response
    {
        $f = ['project' => (string) $r->input('project', ''), 'status' => (string) $r->input('status', '')];
        $manageable = array_values(array_filter(ProjectService::list([]), fn($p) => Auth::isAdmin() || Auth::supervises((int) $p['id'])));
        $assignable = [];
        foreach ($manageable as $p) {
            $assignable[$p['id']] = TaskService::assignable((int) $p['id']);
        }
        return $this->view('tasks/index', ['title' => 'Tasks', 'rows' => TaskService::list($f), 'f' => $f, 'manageable' => $manageable, 'assignable' => $assignable]);
    }

    public function save(Request $r): Response
    {
        return $this->attempt(function () use ($r) {
            $id = $r->int('task_id');
            TaskService::save($id, (int) $r->input('project_id'), $r->post);
            return $this->redirect('/tasks', 'Task saved.');
        }, '/tasks');
    }
}
