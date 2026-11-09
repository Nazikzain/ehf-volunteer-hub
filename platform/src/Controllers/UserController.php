<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Security\Auth;
use App\Services\UserService;

final class UserController extends Controller
{
    public function index(Request $r): Response
    {
        $f = ['status' => (string) $r->input('status', ''), 'role' => (string) $r->input('role', ''), 'q' => (string) $r->input('q', '')];
        return $this->view('admin/users', ['title' => 'Users', 'users' => UserService::list($f), 'f' => $f]);
    }

    public function show(Request $r): Response
    {
        return $this->view('admin/user', ['title' => 'User', 'u' => UserService::get($r->param('id')),
            'admins' => array_filter(UserService::list(['role' => 'organisation_admin', 'status' => 'active']), fn($x) => (int) $x['id'] !== Auth::id())]);
    }

    public function invite(Request $r): Response
    {
        return $this->attempt(function () use ($r) {
            $id = UserService::invite((string) $r->input('email', ''), (string) $r->input('role', ''));
            return $this->redirect('/admin/users/' . $id, 'Invitation created and activation email queued.');
        }, '/admin/users');
    }

    public function action(Request $r): Response
    {
        $id = $r->param('id');
        return $this->attempt(function () use ($r, $id) {
            $a = (string) $r->input('action');
            switch ($a) {
                case 'grant': UserService::grantRole($id, (string) $r->input('role')); $m = 'Role granted.'; break;
                case 'revoke': UserService::revokeRole($id, (string) $r->input('role')); $m = 'Role removed.'; break;
                case 'deactivate': UserService::deactivate($id); $m = 'Account deactivated. Existing sessions have ended.'; break;
                case 'reactivate': UserService::reactivate($id); $m = 'Account reactivated.'; break;
                case 'reissue': UserService::reissueActivation($id); $m = 'A new activation link has been queued.'; break;
                case 'anonymise':
                    if ($r->input('confirm1') !== '1' || $r->input('confirm2') !== '1') {
                        throw new \App\Core\ValidationException(['Both confirmations are required to anonymise an account.']);
                    }
                    UserService::anonymise($id); $m = 'Account anonymised.'; break;
                case 'delete':
                    if ($r->input('confirm1') !== '1' || $r->input('confirm2') !== '1') {
                        throw new \App\Core\ValidationException(['Both confirmations are required to delete an invitation.']);
                    }
                    UserService::deleteInvitation($id);
                    return $this->redirect('/admin/users', 'Invitation deleted.');
                default: throw new \App\Core\HttpException(400);
            }
            return $this->redirect('/admin/users/' . $id, $m);
        }, '/admin/users/' . $id);
    }

    public function transfer(Request $r): Response
    {
        return $this->attempt(function () use ($r) {
            UserService::transferOwnership((int) $r->input('target_id'), (string) ($r->post['password'] ?? ''),
                $r->input('confirm1') === '1', $r->input('confirm2') === '1');
            return $this->redirect('/login', 'Ownership transferred. Please sign in again.', 'info');
        }, '/admin/users/' . Auth::id());
    }
}
