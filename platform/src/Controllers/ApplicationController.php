<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\ApplicationService;

final class ApplicationController extends Controller
{
    public function index(Request $r): Response
    {
        $f = ['status' => (string) $r->input('status', ''), 'q' => (string) $r->input('q', '')];
        $res = ApplicationService::list($f, max(1, (int) $r->input('page', 1)));
        return $this->view('admin/applications', ['title' => 'Applications', 'f' => $f] + $res);
    }

    public function show(Request $r): Response
    {
        return $this->view('admin/application', ['title' => 'Application', 'a' => ApplicationService::get($r->param('id'))]);
    }

    public function status(Request $r): Response
    {
        $id = $r->param('id');
        return $this->attempt(function () use ($r, $id) {
            $action = (string) $r->input('action');
            $note = (string) $r->input('note', '');
            if ($action === 'approve') {
                $res = ApplicationService::approve($id, $note, $r->input('confirm_link') === '1');
                return $this->redirect('/volunteers/' . $res['volunteer_id'], 'Application approved.' . ($res['activation_issued'] ? ' An activation link has been queued for the volunteer.' : ''));
            }
            ApplicationService::setStatus($id, $action, $note);
            return $this->redirect('/admin/applications/' . $id, 'Application updated.');
        }, '/admin/applications/' . $id);
    }
}
