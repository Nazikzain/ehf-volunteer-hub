<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Security\Auth;

final class NotificationController extends Controller
{
    public function index(Request $r): Response
    {
        $rows = Database::get()->all('SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 100', [Auth::id()]);
        return $this->view('notifications/index', ['title' => 'Notifications', 'rows' => $rows]);
    }

    public function read(Request $r): Response
    {
        // Scoped to the current user: another user's id simply matches nothing.
        $id = $r->int('id');
        if ($id) {
            Database::get()->run('UPDATE notifications SET read_at = ? WHERE id = ? AND user_id = ? AND read_at IS NULL', [now_utc(), $id, Auth::id()]);
        } else {
            Database::get()->run('UPDATE notifications SET read_at = ? WHERE user_id = ? AND read_at IS NULL', [now_utc(), Auth::id()]);
        }
        return $this->redirect('/notifications');
    }
}
