<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\ProjectService;
use App\Services\VolunteerService;

final class ProjectController extends Controller
{
    public function index(Request $r): Response
    {
        $f = ['status' => (string) $r->input('status', ''), 'q' => (string) $r->input('q', '')];
        return $this->view('projects/index', ['title' => 'Projects', 'projects' => ProjectService::list($f), 'f' => $f]);
    }

    public function show(Request $r): Response
    {
        $p = ProjectService::get($r->param('id'));
        $vols = $p['can_manage'] ? VolunteerService::list(['status' => ''], 1, 500)['rows'] : [];
        if ($p['can_manage'] && !\App\Security\Auth::isAdmin()) {   // supervisors may add approved/active volunteers (names only) to their own project
            $vols = \App\Core\Database::get()->all("SELECT id, full_name, status FROM volunteers WHERE deleted_at IS NULL AND status IN ('approved','active') ORDER BY full_name");
        }
        return $this->view('projects/show', ['title' => $p['name'], 'p' => $p, 'volunteers' => $vols,
            'candidates' => \App\Security\Gate::allows('supervisors.manage') ? ProjectService::supervisorCandidates() : []]);
    }

    public function create(Request $r): Response
    {
        return $this->attempt(function () use ($r) {
            $id = ProjectService::create($r->post);
            return $this->redirect('/projects/' . $id, 'Project created.');
        }, '/projects');
    }

    public function update(Request $r): Response
    {
        $id = $r->param('id');
        return $this->attempt(function () use ($r, $id) {
            ProjectService::update($id, $r->post);
            return $this->redirect('/projects/' . $id, 'Project saved.');
        }, '/projects/' . $id);
    }

    public function supervisors(Request $r): Response
    {
        $id = $r->param('id');
        return $this->attempt(function () use ($r, $id) {
            $uid = (int) $r->input('user_id');
            $r->input('action') === 'end' ? ProjectService::endSupervisor($id, $uid) : ProjectService::assignSupervisor($id, $uid);
            return $this->redirect('/projects/' . $id, 'Supervisors updated.');
        }, '/projects/' . $id);
    }

    public function periods(Request $r): Response
    {
        $id = $r->param('id');
        return $this->attempt(function () use ($r, $id) {
            $a = (string) $r->input('action');
            if ($a === 'create') {
                ProjectService::createPeriod($id, (int) $r->input('volunteer_id'), (string) $r->input('start_date'), (string) $r->input('role_description', '') ?: null);
            } elseif ($a === 'end') {
                ProjectService::endPeriod((int) $r->input('period_id'), (string) $r->input('end_date'), (string) $r->input('status', 'completed'));
            } elseif ($a === 'dates') {
                ProjectService::updatePeriodDates((int) $r->input('period_id'), (string) $r->input('start_date'), ($r->input('end_date') ?: null));
            } else {
                throw new \App\Core\HttpException(400);
            }
            return $this->redirect('/projects/' . $id, 'Service periods updated.');
        }, '/projects/' . $id);
    }
}
