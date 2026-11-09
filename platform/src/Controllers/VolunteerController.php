<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Security\Auth;
use App\Security\Gate;
use App\Services\ErasureService;
use App\Services\NoteService;
use App\Services\ProjectService;
use App\Services\VolunteerService;

final class VolunteerController extends Controller
{
    public function index(Request $r): Response
    {
        $f = ['status' => (string) $r->input('status', ''), 'q' => (string) $r->input('q', ''), 'skill' => (string) $r->input('skill', ''), 'project' => (string) $r->input('project', '')];
        $res = VolunteerService::list($f, max(1, (int) $r->input('page', 1)));
        return $this->view('volunteers/index', ['title' => 'Volunteers', 'f' => $f,
            'skills' => Database::get()->all('SELECT id, name FROM skills WHERE is_active = 1 ORDER BY name'),
            'projects' => ProjectService::list([])] + $res);
    }

    /** Volunteers hold only own-scope access: their own record redirects to /me, any other id is 404 (ST-04..06). */
    private function volunteerScope(int $id): ?Response
    {
        if (Gate::allows('volunteers.view')) {
            return null;
        }
        if (Auth::volunteerId() === $id) {
            return $this->redirect('/me');
        }
        Gate::notFound('volunteer');
    }

    public function show(Request $r): Response
    {
        $id = $r->param('id');
        if ($res = $this->volunteerScope($id)) {
            return $res;
        }
        $v = VolunteerService::get($id);
        $data = ['title' => $v['full_name'], 'v' => $v,
            'skills' => Database::get()->all('SELECT id, name FROM skills WHERE is_active = 1 ORDER BY name'),
            'projects' => ProjectService::list(['status' => 'active']) ];
        if (Auth::isAdmin()) {
            $data['erasureCandidates'] = ErasureService::candidates($id);
        }
        return $this->view('volunteers/show', $data);
    }

    public function update(Request $r): Response
    {
        $id = $r->param('id');
        if (!Gate::allows('volunteers.edit')) {
            if (Auth::volunteerId() === $id) {
                if ($r->input('action') !== 'availability') {
                    Gate::deny(403, ['reason' => 'volunteer_cannot_edit_protected_fields']); // ST-06: name/email need an admin
                }
            } elseif (!Gate::allows('volunteers.view')) {
                Gate::notFound('volunteer');                                        // volunteer acting on another record: 404
            } else {
                Gate::deny(403, ['permission' => 'volunteers.edit']);              // staff without edit permission: 403
            }
        }
        return $this->attempt(function () use ($r, $id) {
            match ((string) $r->input('action')) {
                'details' => VolunteerService::update($id, $r->post),
                'status' => VolunteerService::setStatus($id, (string) $r->input('status'), (string) $r->input('reason', '')),
                'skills' => VolunteerService::setSkills($id, (array) ($r->post['skills'] ?? [])),
                'availability' => VolunteerService::setAvailability($id, (array) ($r->post['slots'] ?? [])),
                default => throw new \App\Core\HttpException(400),
            };
            return $this->redirect('/volunteers/' . $id, 'Saved.');
        }, '/volunteers/' . $id);
    }

    public function addNote(Request $r): Response
    {
        $id = $r->param('id');
        return $this->attempt(function () use ($r, $id) {
            NoteService::create($id, (string) $r->input('body', ''), (string) $r->input('visibility', 'admins'), $r->int('project_id'));
            return $this->redirect('/volunteers/' . $id, 'Note added.');
        }, '/volunteers/' . $id);
    }

    public function deleteNote(Request $r): Response
    {
        $vid = NoteService::delete($r->param('id'));
        return $this->redirect('/volunteers/' . $vid, 'Note removed.');
    }

    public function erasure(Request $r): Response
    {
        $id = $r->param('id');
        return $this->attempt(function () use ($r, $id) {
            $a = (string) $r->input('action');
            if ($a === 'prepare') {
                ErasureService::prepare($id, (string) $r->input('request_ref', ''));
                return $this->redirect('/volunteers/' . $id, 'Erasure request prepared. It now needs Primary Admin approval.');
            }
            if ($a === 'cancel') {
                ErasureService::cancel($id);
                return $this->redirect('/volunteers/' . $id, 'Erasure request cancelled.');
            }
            if ($a === 'approve') {
                $c = ErasureService::approveAndExecute($id, (array) ($r->post['unlinked'] ?? []), $r->input('confirm1') === '1', $r->input('confirm2') === '1');
                return $this->redirect('/volunteers/' . $id, 'Volunteer personal data erased (' . $c['applications_deleted'] . ' application(s) deleted).');
            }
            throw new \App\Core\HttpException(400);
        }, '/volunteers/' . $id);
    }

    /** Volunteer's own profile. */
    public function me(Request $r): Response
    {
        $vid = Auth::volunteerId();
        if ($vid === null) {
            Gate::notFound('volunteer');
        }
        return $this->view('volunteers/me', ['title' => 'My profile', 'v' => VolunteerService::get($vid)]);
    }

    public function meUpdate(Request $r): Response
    {
        return $this->attempt(function () use ($r) {
            if ($r->input('action') === 'availability') {
                VolunteerService::setAvailability((int) Auth::volunteerId(), (array) ($r->post['slots'] ?? []));
            } else {
                VolunteerService::selfUpdate($r->post);
            }
            return $this->redirect('/me', 'Your details have been saved.');
        }, '/me');
    }
}
