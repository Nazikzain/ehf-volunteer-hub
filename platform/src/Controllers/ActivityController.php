<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Security\Auth;
use App\Services\ActivityService;
use App\Services\ProjectService;

final class ActivityController extends Controller
{
    public function index(Request $r): Response
    {
        $f = ['project' => (string) $r->input('project', ''), 'status' => (string) $r->input('status', ''), 'from' => (string) $r->input('from', ''), 'to' => (string) $r->input('to', '')];
        $manageable = array_values(array_filter(ProjectService::list(['status' => 'active']), fn($p) => Auth::isAdmin() || Auth::supervises((int) $p['id'])));
        return $this->view('activities/index', ['title' => 'Activities', 'rows' => ActivityService::list($f), 'f' => $f,
            'projects' => ProjectService::list([]), 'manageable' => $manageable]);
    }

    public function show(Request $r): Response
    {
        $a = ActivityService::get($r->param('id'));
        return $this->view('activities/show', ['title' => $a['title'], 'a' => $a]);
    }

    public function create(Request $r): Response
    {
        return $this->attempt(function () use ($r) {
            $id = ActivityService::create((int) $r->input('project_id'), $r->post);
            return $this->redirect('/activities/' . $id, 'Activity scheduled.');
        }, '/activities');
    }

    public function update(Request $r): Response
    {
        $id = $r->param('id');
        return $this->attempt(function () use ($r, $id) {
            $a = (string) $r->input('action');
            match ($a) {
                'edit' => ActivityService::update($id, $r->post),
                'status' => ActivityService::setStatus($id, (string) $r->input('status')),
                'roster_add' => ActivityService::rosterAdd($id, (int) $r->input('volunteer_id')),
                'roster_remove' => ActivityService::rosterRemove($id, (int) $r->input('volunteer_id')),
                'attendance' => ActivityService::recordAttendance($id, (array) ($r->post['attendance'] ?? [])),
                default => throw new \App\Core\HttpException(400),
            };
            return $this->redirect('/activities/' . $id, 'Saved.');
        }, '/activities/' . $id);
    }
}
