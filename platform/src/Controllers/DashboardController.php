<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Audit\AuditAnchor;
use App\Core\Request;
use App\Core\Response;
use App\Security\Auth;
use App\Security\Gate;
use App\Services\DashboardService;
use App\Services\Mailer;

final class DashboardController extends Controller
{
    public function index(Request $r): Response
    {
        $data = ['title' => 'Dashboard', 'announcements' => DashboardService::announcements()];
        if (Gate::allows('dashboard.admin')) {
            // Daily tasks where cron is unavailable: anchor + outbox processing on the first admin request (Design §6.8a).
            try {
                AuditAnchor::dailyIfDue();
                Mailer::process(10);
            } catch (\Throwable $e) {
                \App\Core\ErrorHandler::log($e, $r->id);
            }
            $data['admin'] = DashboardService::admin();
        }
        if (Auth::hasRole('project_supervisor') && !Auth::isAdmin()) {
            $data['sup'] = DashboardService::supervisor();
        }
        if (Auth::volunteerId() !== null && Gate::allows('dashboard.volunteer')) {
            $data['vol'] = DashboardService::volunteer();
        }
        return $this->view('dashboard/index', $data);
    }
}
