<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Security\Auth;
use App\Services\CertificateService;
use App\Services\ReviewService;
use App\Services\TrainingService;

/** Reviews, training and certificates. */
final class DevelopmentController extends Controller
{
    public function reviews(Request $r): Response
    {
        $vid = $r->param('id');
        if (!\App\Security\Gate::allows('reviews.write')) {
            if (Auth::volunteerId() === $vid) {
                return $this->redirect('/my/reviews');
            }
            \App\Security\Gate::notFound('volunteer');
        }
        \App\Services\Scope::assertViewVolunteer($vid);
        $v = \App\Core\Database::get()->one('SELECT id, full_name FROM volunteers WHERE id = ?', [$vid]);
        $projects = \App\Core\Database::get()->all('SELECT DISTINCT p.id, p.name FROM project_assignments pa JOIN projects p ON p.id = pa.project_id WHERE pa.volunteer_id = ?', [$vid]);
        if (!Auth::isAdmin()) {
            $projects = array_values(array_filter($projects, fn($p) => Auth::supervises((int) $p['id'])));
        }
        return $this->view('development/reviews', ['title' => 'Performance reviews', 'v' => $v, 'reviews' => ReviewService::forVolunteer($vid), 'projects' => $projects, 'canWrite' => true]);
    }

    public function myReviews(Request $r): Response
    {
        $vid = (int) Auth::volunteerId();
        return $this->view('development/reviews', ['title' => 'My reviews', 'v' => ['id' => $vid, 'full_name' => 'My reviews'], 'reviews' => ReviewService::forVolunteer($vid), 'projects' => [], 'canWrite' => false]);
    }

    public function addReview(Request $r): Response
    {
        $vid = $r->param('id');
        return $this->attempt(function () use ($r, $vid) {
            ReviewService::create($vid, $r->post);
            return $this->redirect("/volunteers/{$vid}/reviews", 'Review saved.');
        }, "/volunteers/{$vid}/reviews");
    }

    public function deleteReview(Request $r): Response
    {
        $vid = ReviewService::delete($r->param('id'));
        return $this->redirect("/volunteers/{$vid}/reviews", 'Review removed.');
    }

    public function types(Request $r): Response
    {
        return $this->view('development/training_types', ['title' => 'Training types', 'types' => TrainingService::types(), 'compliance' => TrainingService::compliance()]);
    }

    public function saveType(Request $r): Response
    {
        return $this->attempt(function () use ($r) {
            TrainingService::saveType($r->int('id'), $r->post);
            return $this->redirect('/admin/training-types', 'Training type saved.');
        }, '/admin/training-types');
    }

    public function recordTraining(Request $r): Response
    {
        $vid = $r->param('id');
        return $this->attempt(function () use ($r, $vid) {
            TrainingService::record($vid, $r->post);
            return $this->redirect("/volunteers/{$vid}", 'Training recorded.');
        }, "/volunteers/{$vid}");
    }

    public function certificates(Request $r): Response
    {
        return $this->view('development/certificates', ['title' => 'Certificates', 'rows' => CertificateService::list(),
            'volunteers' => Auth::isAdmin() ? \App\Core\Database::get()->all("SELECT id, full_name FROM volunteers WHERE deleted_at IS NULL AND anonymised_at IS NULL ORDER BY full_name") : []]);
    }

    public function createCertificate(Request $r): Response
    {
        return $this->attempt(function () use ($r) {
            CertificateService::create((int) $r->input('volunteer_id'), $r->input('required_hours') !== '' ? $r->int('required_hours') : null, (string) $r->input('notes', ''));
            return $this->redirect('/certificates', 'Certificate record created.');
        }, '/certificates');
    }

    public function advanceCertificate(Request $r): Response
    {
        return $this->attempt(function () use ($r) {
            CertificateService::advance($r->param('id'), (string) $r->input('status'));
            return $this->redirect('/certificates', 'Certificate updated.');
        }, '/certificates');
    }

    public function printCertificate(Request $r): Response
    {
        $c = CertificateService::forPrint($r->param('id'));
        return Response::html(View::render('development/certificate_print', ['c' => $c, 'title' => 'Certificate'], null));
    }
}
