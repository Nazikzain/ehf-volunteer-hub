<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Security\Auth;
use App\Services\ProjectService;
use App\Services\TimeLogService;

final class HoursController extends Controller
{
    public function mine(Request $r): Response
    {
        $vid = (int) Auth::volunteerId();
        return $this->view('hours/mine', ['title' => 'My hours', 'logs' => TimeLogService::mine($vid), 'opt' => TimeLogService::options($vid),
            'resubmit' => $r->int('resubmit')]);
    }

    public function submit(Request $r): Response
    {
        return $this->attempt(function () use ($r) {
            [, $warnings] = TimeLogService::submit($r->post);
            foreach ($warnings as $w) {
                Session::flash('warning', $w);
            }
            return $this->redirect('/my/hours', 'Your hours have been submitted for approval.');
        }, '/my/hours');
    }

    public function edit(Request $r): Response
    {
        return $this->attempt(function () use ($r) {
            TimeLogService::edit($r->param('id'), $r->post);
            return $this->redirect('/my/hours', 'Updated and resubmitted for approval.');
        }, '/my/hours');
    }

    public function queue(Request $r): Response
    {
        $f = ['status' => in_array($r->input('status'), ['pending', 'approved', 'rejected', 'revision_requested', 'voided'], true) ? $r->input('status') : 'pending',
            'project' => (string) $r->input('project', '')];
        return $this->view('hours/review', ['title' => 'Hours approval', 'rows' => TimeLogService::reviewQueue($f), 'f' => $f,
            'projects' => array_values(array_filter(ProjectService::list([]), fn($p) => Auth::isAdmin() || Auth::supervises((int) $p['id'])))]);
    }

    public function review(Request $r): Response
    {
        return $this->attempt(function () use ($r) {
            TimeLogService::review($r->param('id'), (string) $r->input('decision'), (string) $r->input('comment', ''));
            return $this->redirect('/hours/review?status=' . urlencode((string) $r->input('back', 'pending')), 'Review saved.');
        }, '/hours/review');
    }
}
