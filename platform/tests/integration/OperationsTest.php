<?php
declare(strict_types=1);

use App\Core\Env;
use App\Core\Session;
use App\Services\Settings;
use Tests\T;

function ops_fx(): array
{
    $s = bin2hex(random_bytes(3));
    $P = T::project("Ops {$s}");
    $oa = T::user("ops.oa.{$s}@example.test", ['organisation_admin']);
    $ps = T::user("ops.ps.{$s}@example.test", ['project_supervisor']);
    T::supervise($P, $ps);
    $u = T::user("ops.v.{$s}@example.test", ['volunteer']);
    $v = T::volunteer("Ops Vol {$s}", $u);
    $per = T::period($P, $v, '2026-01-01');
    return compact('s', 'P', 'oa', 'ps', 'u', 'v', 'per');
}

function days(int $n): string
{
    return (new DateTimeImmutable(today_local()))->modify(($n >= 0 ? '+' : '') . $n . ' days')->format('Y-m-d');
}

T::test('operations', 'attendance: present cannot be recorded before the activity date; correction audited with old/new', function () {
    $f = ops_fx();
    $fut = T::activity($f['P'], days(5));
    T::roster($fut, $f['P'], $f['v'], $f['per']);
    T::actingAs($f['ps']);
    T::post("/activities/{$fut}", ['action' => 'attendance', 'attendance' => [$f['v'] => 'present']]);
    T::eq(0, (int) T::db()->value('SELECT COUNT(*) FROM attendance WHERE activity_id = ?', [$fut]), 'future present refused');
    $past = T::activity($f['P'], days(-1));
    T::roster($past, $f['P'], $f['v'], $f['per']);
    T::post("/activities/{$past}", ['action' => 'attendance', 'attendance' => [$f['v'] => 'absent']]);
    T::post("/activities/{$past}", ['action' => 'attendance', 'attendance' => [$f['v'] => 'late']]);
    T::eq('late', T::db()->value('SELECT status FROM attendance WHERE activity_id = ?', [$past]));
    T::assert((bool) T::db()->value("SELECT 1 FROM audit_logs WHERE action = 'attendance.correct' AND details = ?", ['{"from":"absent","to":"late"}']), 'old/new in audit');
});

T::test('operations', 'activities: cannot cancel with attendance; cannot complete in future; roster removal blocked once attendance exists', function () {
    $f = ops_fx();
    $past = T::activity($f['P'], days(-1));
    T::roster($past, $f['P'], $f['v'], $f['per']);
    T::actingAs($f['ps']);
    T::post("/activities/{$past}", ['action' => 'attendance', 'attendance' => [$f['v'] => 'present']]);
    T::post("/activities/{$past}", ['action' => 'status', 'status' => 'cancelled']);
    T::eq('scheduled', T::db()->value('SELECT status FROM activities WHERE id = ?', [$past]));
    T::post("/activities/{$past}", ['action' => 'roster_remove', 'volunteer_id' => $f['v']]);
    T::eq(1, (int) T::db()->value('SELECT COUNT(*) FROM activity_assignments WHERE activity_id = ?', [$past]));
    $fut = T::activity($f['P'], days(3));
    T::post("/activities/{$fut}", ['action' => 'status', 'status' => 'completed']);
    T::eq('scheduled', T::db()->value('SELECT status FROM activities WHERE id = ?', [$fut]));
});

T::test('operations', 'activity lead must be a current supervisor; date change refused when it leaves a rostered period', function () {
    $f = ops_fx();
    $other = T::user('ops.notsup.' . $f['s'] . '@example.test', ['project_supervisor']);
    T::actingAs($f['oa']);
    T::post('/activities', ['project_id' => $f['P'], 'title' => 'Lead test ' . $f['s'], 'activity_date' => days(4), 'start_time' => '10:00', 'end_time' => '11:00',
        'venue' => 'Hall', 'lead_supervisor_id' => $other]);
    T::eq(0, (int) T::db()->value('SELECT COUNT(*) FROM activities WHERE title = ?', ['Lead test ' . $f['s']]));
    $v2 = T::volunteer('Short ' . $f['s']);
    $per2 = T::period($f['P'], $v2, days(-10), days(10), 'completed');
    $a = T::activity($f['P'], days(5));
    T::roster($a, $f['P'], $v2, $per2);
    T::post("/activities/{$a}", ['action' => 'edit', 'title' => 'Moved', 'activity_date' => days(20), 'start_time' => '10:00', 'end_time' => '11:00', 'venue' => 'Hall']);
    T::eq(days(5), T::db()->value('SELECT activity_date FROM activities WHERE id = ?', [$a]), 'date unchanged');
});

T::test('operations', 'time logs: general work, revision round-trip, rejection then resubmission, 24h day cap', function () {
    $f = ops_fx();
    $db = T::db();
    T::actingAs($f['u']);
    T::post('/my/hours', ['kind' => 'general', 'project_id' => $f['P'], 'work_date' => days(-1), 'hours' => '3', 'description' => 'Office help']);
    $id = (int) $db->value('SELECT id FROM time_logs WHERE volunteer_id = ? ORDER BY id DESC LIMIT 1', [$f['v']]);
    T::eq(180, (int) $db->value('SELECT minutes FROM time_logs WHERE id = ?', [$id]));
    T::actingAs($f['ps']);
    T::post("/hours/{$id}/review", ['decision' => 'revision']); // comment required
    T::eq('pending', $db->value('SELECT status FROM time_logs WHERE id = ?', [$id]));
    T::post("/hours/{$id}/review", ['decision' => 'revision', 'comment' => 'Please split out travel time']);
    T::eq('revision_requested', $db->value('SELECT status FROM time_logs WHERE id = ?', [$id]));
    T::actingAs($f['u']);
    T::post("/my/hours/{$id}", ['hours' => '2.5', 'description' => 'Office help (no travel)']);
    T::eq('pending', $db->value('SELECT status FROM time_logs WHERE id = ?', [$id]));
    T::actingAs($f['ps']);
    T::post("/hours/{$id}/review", ['decision' => 'reject', 'comment' => 'Not project work']);
    T::actingAs($f['u']);
    T::post('/my/hours', ['kind' => 'general', 'project_id' => $f['P'], 'work_date' => days(-1), 'hours' => '2', 'description' => 'Corrected', 'resubmission_of_id' => $id]);
    T::eq((string) $id, (string) $db->value('SELECT resubmission_of_id FROM time_logs WHERE volunteer_id = ? ORDER BY id DESC LIMIT 1', [$f['v']]));
    T::post('/my/hours', ['kind' => 'general', 'project_id' => $f['P'], 'work_date' => days(-1), 'hours' => '23', 'description' => 'Too much']);
    T::eq(0, (int) $db->value("SELECT COUNT(*) FROM time_logs WHERE volunteer_id = ? AND description = 'Too much'", [$f['v']]), '24h cap per day');
    T::post('/my/hours', ['kind' => 'general', 'project_id' => $f['P'], 'work_date' => days(2), 'hours' => '1', 'description' => 'Future']);
    T::eq(0, (int) $db->value("SELECT COUNT(*) FROM time_logs WHERE description = 'Future'"), 'future date refused');
});

T::test('operations', 'time logs: late window after a period ends (D-17 default 30 days)', function () {
    $f = ops_fx();
    $db = T::db();
    $Q = T::project('Late ' . $f['s']);
    T::period($Q, $f['v'], days(-100), days(-60), 'completed');
    T::actingAs($f['u']);
    T::post('/my/hours', ['kind' => 'general', 'project_id' => $Q, 'work_date' => days(-70), 'hours' => '1', 'description' => 'Too late']);
    T::eq(0, (int) $db->value("SELECT COUNT(*) FROM time_logs WHERE description = 'Too late'"));
    $R = T::project('Recent ' . $f['s']);
    T::period($R, $f['v'], days(-40), days(-10), 'completed');
    T::post('/my/hours', ['kind' => 'general', 'project_id' => $R, 'work_date' => days(-15), 'hours' => '1', 'description' => 'In window']);
    T::eq(1, (int) $db->value("SELECT COUNT(*) FROM time_logs WHERE description = 'In window'"));
    T::post('/my/hours', ['kind' => 'general', 'project_id' => $R, 'work_date' => days(-5), 'hours' => '1', 'description' => 'After end']);
    T::eq(0, (int) $db->value("SELECT COUNT(*) FROM time_logs WHERE description = 'After end'"), 'outside the period');
});

T::test('operations', 'certificates: eligibility needs approved hours; issue snapshots hours; flow enforced', function () {
    $f = ops_fx();
    $db = T::db();
    T::actingAs($f['oa']);
    T::post('/certificates', ['volunteer_id' => $f['v'], 'required_hours' => '2', 'notes' => '']);
    $c = (int) $db->value('SELECT id FROM certificates WHERE volunteer_id = ?', [$f['v']]);
    T::eq('not_eligible', $db->value('SELECT status FROM certificates WHERE id = ?', [$c]));
    T::post("/certificates/{$c}", ['status' => 'eligible']);
    T::eq('not_eligible', $db->value('SELECT status FROM certificates WHERE id = ?', [$c]), 'not enough hours');
    $db->insert('time_logs', ['volunteer_id' => $f['v'], 'project_id' => $f['P'], 'project_assignment_id' => $f['per'], 'work_date' => days(-2), 'minutes' => 150,
        'description' => 'x', 'status' => 'approved', 'reviewed_by' => $f['ps'], 'reviewed_at' => now_utc(), 'submitted_by' => $f['u'], 'submitted_at' => now_utc(),
        'created_at' => now_utc(), 'updated_at' => now_utc()]);
    foreach (['eligible', 'approved', 'issued'] as $st) {
        T::post("/certificates/{$c}", ['status' => $st]);
    }
    $row = $db->one('SELECT * FROM certificates WHERE id = ?', [$c]);
    T::eq('issued', $row['status']);
    T::eq(150, (int) $row['approved_minutes_at_issue']);
    T::assert(str_starts_with((string) $row['reference'], 'EHF-'), 'reference assigned');
    T::post("/certificates/{$c}", ['status' => 'eligible']);
    T::eq('issued', $db->value('SELECT status FROM certificates WHERE id = ?', [$c]), 'no going backwards');
    T::actingAs($f['u']);
    $page = T::get("/certificates/{$c}");
    T::assert(str_contains($page->body, '2.5 hours'), 'printable certificate shows hours at issue');
});

T::test('operations', 'training: expiry computed from validity; compliance shows expired/missing; renewal adds a row', function () {
    $f = ops_fx();
    $db = T::db();
    T::actingAs($f['oa']);
    T::post('/admin/training-types', ['name' => 'Safeguarding ' . $f['s'], 'validity_months' => '12', 'is_mandatory' => '1']);
    $t = (int) $db->value('SELECT id FROM training_types WHERE name = ?', ['Safeguarding ' . $f['s']]);
    T::post("/volunteers/{$f['v']}/training", ['training_type_id' => $t, 'status' => 'completed', 'completed_on' => days(-400)]);
    T::eq((new DateTimeImmutable(days(-400)))->modify('+12 months')->format('Y-m-d'), $db->value('SELECT expires_on FROM volunteer_training WHERE volunteer_id = ?', [$f['v']]));
    $states = array_column(array_filter(App\Services\TrainingService::compliance(), fn($r) => (int) $r['volunteer_id'] === $f['v']), 'state');
    T::eq(['expired'], array_values(array_unique($states)));
    T::post("/volunteers/{$f['v']}/training", ['training_type_id' => $t, 'status' => 'completed', 'completed_on' => days(-1)]);
    T::eq(2, (int) $db->value('SELECT COUNT(*) FROM volunteer_training WHERE volunteer_id = ?', [$f['v']]), 'renewal is a new row');
    $states = array_column(array_filter(App\Services\TrainingService::compliance(), fn($r) => (int) $r['volunteer_id'] === $f['v']), 'state');
    T::eq(['ok'], array_values(array_unique($states)));
});

T::test('operations', 'announcements: supervisor may post only to own project; audience visibility respected', function () {
    $f = ops_fx();
    $Q = T::project('Other ' . $f['s']);
    T::actingAs($f['ps']);
    T::post('/announcements', ['title' => 'All staff ' . $f['s'], 'body' => 'x', 'audience' => 'all']);
    T::post('/announcements', ['title' => 'Q only ' . $f['s'], 'body' => 'x', 'audience' => 'project', 'project_id' => $Q]);
    T::post('/announcements', ['title' => 'P team ' . $f['s'], 'body' => 'Hello team', 'audience' => 'project', 'project_id' => $f['P']]);
    T::eq(1, (int) T::db()->value('SELECT COUNT(*) FROM announcements WHERE title LIKE ?', ['%' . $f['s']]));
    T::actingAs($f['u']);
    T::assert(str_contains(T::get('/dashboard')->body, 'P team ' . $f['s']), 'volunteer on P sees it');
    $other = T::user('ann.other.' . $f['s'] . '@example.test', ['volunteer']);
    T::volunteer('Ann other ' . $f['s'], $other);
    T::actingAs($other);
    T::assert(!str_contains(T::get('/dashboard')->body, 'P team ' . $f['s']), 'volunteer not on P does not');
});

T::test('operations', 'reports: monthly figures equal the underlying approved records; invalid period rejected', function () {
    $f = ops_fx();
    $db = T::db();
    $month = substr(days(-1), 0, 7);
    $db->insert('time_logs', ['volunteer_id' => $f['v'], 'project_id' => $f['P'], 'project_assignment_id' => $f['per'], 'work_date' => days(-1), 'minutes' => 90,
        'description' => 'x', 'status' => 'approved', 'reviewed_by' => $f['ps'], 'reviewed_at' => now_utc(), 'submitted_by' => $f['u'], 'submitted_at' => now_utc(),
        'created_at' => now_utc(), 'updated_at' => now_utc()]);
    $expected = (int) $db->value("SELECT SUM(minutes) FROM time_logs WHERE project_id = ? AND status = 'approved' AND DATE_FORMAT(work_date, '%Y-%m') = ?", [$f['P'], $month]);
    T::actingAs($f['oa']);
    App\Security\Auth::loadFromSession();
    $sum = App\Services\ReportService::summary(App\Services\ReportService::period('month', $month), $f['P']);
    T::eq($expected, (int) $sum['total_minutes']);
    T::actingAs(null);
    App\Security\Auth::loadFromSession();
    T::eq(0, (int) App\Services\ReportService::summary(App\Services\ReportService::period('month', $month), null)['total_minutes'], 'no scope, no data');
    T::actingAs($f['oa']);
    T::eq(303, T::get('/reports', ['type' => 'month', 'value' => '2026-13'])->status, 'invalid month redirected with error');
    T::eq(200, T::get('/reports', ['type' => 'year', 'value' => substr($month, 0, 4)])->status);
});

T::test('operations', 'integrity report detects a planted service-layer violation and changes nothing', function () {
    $f = ops_fx();
    $db = T::db();
    $before = App\Services\IntegrityReport::run(false)['violations'];
    $db->insert('time_logs', ['volunteer_id' => $f['v'], 'project_id' => $f['P'], 'project_assignment_id' => $f['per'], 'work_date' => '2025-12-01', 'minutes' => 30,
        'description' => 'before period', 'status' => 'pending', 'submitted_by' => $f['u'], 'submitted_at' => now_utc(), 'created_at' => now_utc(), 'updated_at' => now_utc()]);
    $rep = App\Services\IntegrityReport::run(false);
    T::eq($before + 1, $rep['violations']);
    T::eq(1, (int) $db->value("SELECT COUNT(*) FROM time_logs WHERE description = 'before period'"), 'report never auto-corrects');
});

T::test('operations', 'retention: personal-data purges report only by default; technical purges run', function () {
    $db = T::db();
    $u = T::user('ret.' . bin2hex(random_bytes(3)) . '@example.test', ['volunteer']);
    $db->insert('notifications', ['user_id' => $u, 'type' => 't', 'title' => 'old', 'created_at' => '2020-01-01 00:00:00']);
    $r = App\Services\Retention::run();
    T::assert(str_starts_with($r['mode'], 'report_only'), 'report only');
    T::assert($r['notifications'] >= 1, 'counted');
    T::eq(1, (int) $db->value("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND title = 'old'", [$u]), 'not deleted');
    Settings::set('retention.auto_purge_enabled', 'true', null);
    App\Services\Retention::run();
    T::eq(0, (int) $db->value("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND title = 'old'", [$u]), 'deleted once enabled');
    Settings::set('retention.auto_purge_enabled', 'false', null);
});

T::test('operations', 'email outbox: log transport sends and erases the body; disabled keeps it queued; production refuses log transport', function () {
    $db = T::db();
    $id = App\Services\Mailer::queue('outbox@example.test', 't', 'Subject', 'Body with link');
    Env::set('MAIL_TRANSPORT', 'disabled');
    App\Services\Mailer::process();
    T::eq('queued', $db->value('SELECT status FROM email_outbox WHERE id = ?', [$id]));
    Env::set('MAIL_TRANSPORT', 'log');
    App\Services\Mailer::process();
    $row = $db->one('SELECT status, body FROM email_outbox WHERE id = ?', [$id]);
    T::eq('sent', $row['status']);
    T::eq(null, $row['body'], 'body erased after sending');
    $id2 = App\Services\Mailer::queue('prod@example.test', 't', 'S', 'B');
    Env::set('APP_ENV', 'production');
    App\Services\Mailer::process();
    Env::set('APP_ENV', 'development');
    Env::set('MAIL_TRANSPORT', 'disabled');
    T::assert(str_contains((string) $db->value('SELECT last_error FROM email_outbox WHERE id = ?', [$id2]), 'not permitted in production'), 'log transport refused in production');
});

T::test('operations', 'search and filters work (volunteers, applications, users) without errors', function () {
    $f = ops_fx();
    T::actingAs($f['oa']);
    $r = T::get('/volunteers', ['q' => 'Ops Vol ' . $f['s']]);
    T::eq(200, $r->status);
    T::assert(str_contains($r->body, 'Ops Vol ' . $f['s']), 'found');
    T::eq(200, T::get('/admin/applications', ['q' => 'x', 'status' => 'pending'])->status);
    T::eq(200, T::get('/admin/users', ['q' => 'ops', 'role' => 'volunteer', 'status' => 'active'])->status);
    T::eq(200, T::get('/activities', ['project' => (string) $f['P'], 'from' => '2026-01-01'])->status);
});

T::test('operations', 'settings: validated, audited, secrets never editable', function () {
    $f = ops_fx();
    T::actingAs($f['oa']);
    T::post('/admin/settings', ['timelogs__late_submission_days' => 'abc']);
    T::eq('30', Settings::get('timelogs.late_submission_days'));
    T::post('/admin/settings', ['privacy__notice_url' => 'http://insecure.example']);
    T::assert(Settings::get('privacy.notice_url') !== 'http://insecure.example', 'https required');
    T::post('/admin/settings', ['timelogs__late_submission_days' => '45']);
    T::eq('45', Settings::get('timelogs.late_submission_days'));
    T::assert((bool) T::db()->value("SELECT 1 FROM audit_logs WHERE action = 'settings.update'"), 'audited');
    T::post('/admin/settings', ['timelogs__late_submission_days' => '30']);
    T::assert(!str_contains(T::get('/admin/settings')->body, (string) env('AUDIT_KEY_1')), 'no secrets shown');
});

T::test('operations', 'every page in the navigation renders for each role without server errors', function () {
    $f = ops_fx();
    $pages = ['/dashboard', '/notifications', '/admin/applications', '/volunteers', '/me', '/my/reviews', '/projects', '/activities', '/tasks', '/my/hours', '/hours/review',
        '/admin/training-types', '/certificates', '/announcements', '/reports', '/admin/users', '/admin/audit', '/admin/integrity', '/admin/outbox', '/admin/settings',
        "/projects/{$f['P']}", "/volunteers/{$f['v']}", "/volunteers/{$f['v']}/reviews"];
    foreach (['owner' => T::$ownerId, 'oa' => $f['oa'], 'ps' => $f['ps'], 'vol' => $f['u']] as $role => $uid) {
        foreach ($pages as $p) {
            T::actingAs($uid);
            $st = T::get($p)->status;
            T::assert($st !== 500, "{$role} {$p} returned 500");
            T::assert(in_array($st, [200, 303, 403, 404], true), "{$role} {$p} unexpected {$st}");
        }
    }
    foreach (['/', '/apply', '/apply/thank-you', '/login', '/forgot-password', '/activate', '/reset-password'] as $p) {
        T::actingAs(null);
        T::assert(in_array(T::get($p)->status, [200, 303], true), "public {$p}");
    }
});
