<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\ValidationException;
use App\Services\ApplicationService;
use App\Services\Settings;

final class PublicController extends Controller
{
    public function applyForm(Request $r): Response
    {
        return $this->view('public/apply', ['title' => 'Volunteer with us', 'old' => Session::get('old_apply', []),
            'consent' => Settings::get('consent.text', ''), 'privacyUrl' => Settings::get('privacy.notice_url', '#')], 'public');
    }

    public function apply(Request $r): Response
    {
        try {
            ApplicationService::submit($r->post, $r->ip());
        } catch (ValidationException $e) {
            $old = $r->post;
            unset($old['_csrf']);
            Session::set('old_apply', $old);
            foreach ($e->errors as $m) {
                Session::flash('error', $m);
            }
            return Response::redirect(url('/apply'));
        }
        Session::forget('old_apply');
        // Same message whether stored or dropped as a duplicate (no enumeration, ST-40).
        return $this->redirect('/apply/thank-you');
    }

    public function thanks(Request $r): Response
    {
        return $this->view('public/thanks', ['title' => 'Thank you'], 'public');
    }
}
