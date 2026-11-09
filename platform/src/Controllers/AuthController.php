<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\ValidationException;
use App\Security\Auth;
use App\Services\AuthService;

final class AuthController extends Controller
{
    public function home(Request $r): Response
    {
        return Auth::check() ? $this->redirect('/dashboard') : $this->redirect('/apply');
    }

    public function loginForm(Request $r): Response
    {
        if (Auth::check()) {
            return $this->redirect('/dashboard');
        }
        return $this->view('auth/login', ['title' => 'Sign in'], 'public');
    }

    public function login(Request $r): Response
    {
        try {
            $ok = AuthService::attempt((string) $r->input('email', ''), (string) ($r->post['password'] ?? ''), $r->ip());
        } catch (ValidationException $e) {
            return $this->redirect('/login', $e->getMessage(), 'error');
        }
        if (!$ok) {
            return $this->redirect('/login', AuthService::GENERIC_LOGIN_ERROR, 'error');
        }
        $intended = Session::get('intended');
        Session::forget('intended');
        $to = is_string($intended) && str_starts_with($intended, '/') && !str_starts_with($intended, '//') ? $intended : '/dashboard';
        return $this->redirect($to);
    }

    public function logout(Request $r): Response
    {
        AuthService::logout();
        return $this->redirect('/login', 'You have signed out.', 'info');
    }

    public function activateForm(Request $r): Response
    {
        return $this->view('auth/set_password', ['title' => 'Activate your account', 'token' => (string) $r->input('token', ''),
            'action' => '/activate', 'heading' => 'Activate your account'], 'public');
    }

    public function activate(Request $r): Response
    {
        $token = (string) $r->input('token', '');
        try {
            $u = AuthService::activate($token, (string) ($r->post['password'] ?? ''), (string) ($r->post['password_confirm'] ?? ''), $r->ip());
        } catch (ValidationException $e) {
            foreach ($e->errors as $m) {
                Session::flash('error', $m);
            }
            return Response::redirect(url('/activate', ['token' => $token]));
        }
        AuthService::startSession($u);
        return $this->redirect('/dashboard', 'Your account is active. Welcome to the EHF Volunteer Hub.');
    }

    public function forgotForm(Request $r): Response
    {
        return $this->view('auth/forgot', ['title' => 'Reset your password'], 'public');
    }

    public function forgot(Request $r): Response
    {
        AuthService::requestReset((string) $r->input('email', ''), $r->ip());
        return $this->redirect('/login', 'If an active account exists for that address, a reset link has been sent.', 'info');
    }

    public function resetForm(Request $r): Response
    {
        return $this->view('auth/set_password', ['title' => 'Choose a new password', 'token' => (string) $r->input('token', ''),
            'action' => '/reset-password', 'heading' => 'Choose a new password'], 'public');
    }

    public function reset(Request $r): Response
    {
        $token = (string) $r->input('token', '');
        try {
            AuthService::resetPassword($token, (string) ($r->post['password'] ?? ''), (string) ($r->post['password_confirm'] ?? ''));
        } catch (ValidationException $e) {
            foreach ($e->errors as $m) {
                Session::flash('error', $m);
            }
            return Response::redirect(url('/reset-password', ['token' => $token]));
        }
        return $this->redirect('/login', 'Your password has been changed. Please sign in.');
    }
}
