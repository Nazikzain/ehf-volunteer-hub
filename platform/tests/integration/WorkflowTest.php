<?php
declare(strict_types=1);

use App\Core\Session;
use Tests\T;

function apply_form(array $over = []): array
{
    T::db()->run("DELETE FROM rate_limit_hits WHERE action = 'registration'"); // tests share one synthetic IP
    return $over + ['full_name' => 'Amina Synthetic', 'email' => 'amina.synthetic@example.test', 'phone' => '07700 900123', 'postcode' => 'M1 1AE',
        'expertise' => 'IT & Digital Skills', 'availability_type' => 'specific_days', 'available_days' => ['1', '3'],
        'referral_source' => 'Website', 'motivation' => 'Synthetic test motivation.', 'privacy_consent' => '1'];
}

T::test('workflow', 'public application stored with all form fields, history and admin notification', function () {
    $r = T::post('/apply', apply_form());
    T::eq('/apply/thank-you', $r->headers['Location']);
    $db = T::db();
    $a = $db->one("SELECT * FROM volunteer_applications WHERE email = 'amina.synthetic@example.test'");
    T::eq('pending', $a['status']);
    T::eq(10, strlen($a['reference']));
    T::eq([1, 3], array_map('intval', array_column($db->all('SELECT weekday FROM application_available_days WHERE application_id = ? ORDER BY weekday', [$a['id']]), 'weekday')));
    T::eq('IT & Digital Skills', $db->value('SELECT s.name FROM application_skills x JOIN skills s ON s.id = x.skill_id WHERE x.application_id = ?', [$a['id']]));
    T::eq(1, (int) $db->value('SELECT COUNT(*) FROM application_status_history WHERE application_id = ?', [$a['id']]));
    T::assert((int) $db->value("SELECT COUNT(*) FROM notifications WHERE type = 'application'") >= 1, 'admins notified');
});

T::test('workflow', 'validation: specific days require a day; consent required; bad email rejected', function () {
    T::post('/apply', apply_form(['email' => 'v1@example.test', 'available_days' => []]));
    T::post('/apply', apply_form(['email' => 'v2@example.test', 'privacy_consent' => '']));
    T::post('/apply', apply_form(['email' => 'not-an-email']));
    T::eq(0, (int) T::db()->value("SELECT COUNT(*) FROM volunteer_applications WHERE email IN ('v1@example.test','v2@example.test','not-an-email')"));
});

T::test('workflow', 'duplicate open application: same thank-you page, nothing stored (ST-40)', function () {
    T::post('/apply', apply_form(['email' => 'twice@example.test']));
    $r = T::post('/apply', apply_form(['email' => 'twice@example.test']));
    T::eq('/apply/thank-you', $r->headers['Location']);
    T::eq(1, (int) T::db()->value("SELECT COUNT(*) FROM volunteer_applications WHERE email = 'twice@example.test'"));
});

T::test('workflow', 'approval creates volunteer, copies skills/availability, invites volunteer-only account, audits', function () {
    $db = T::db();
    T::post('/apply', apply_form(['email' => 'approve.me@example.test']));
    $aid = (int) $db->value("SELECT id FROM volunteer_applications WHERE email = 'approve.me@example.test'");
    $admin = T::user('approver@example.test', ['organisation_admin']);
    T::actingAs($admin);
    $r = T::post("/admin/applications/{$aid}/status", ['action' => 'approve', 'note' => 'Good fit']);
    T::eq(303, $r->status);
    $v = $db->one("SELECT * FROM volunteers WHERE email = 'approve.me@example.test'");
    T::eq('approved', $v['status']);
    T::eq('approved', $db->value('SELECT status FROM volunteer_applications WHERE id = ?', [$aid]));
    T::eq(1, (int) $db->value('SELECT COUNT(*) FROM volunteer_skills WHERE volunteer_id = ?', [$v['id']]));
    T::eq(2, (int) $db->value('SELECT COUNT(*) FROM volunteer_availability WHERE volunteer_id = ?', [$v['id']]));
    $roles = array_column($db->all('SELECT r.code FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ?', [$v['user_id']]), 'code');
    T::eq(['volunteer'], $roles, 'public registration never grants staff roles');
    T::eq('invited', $db->value('SELECT status FROM users WHERE id = ?', [$v['user_id']]));
    T::eq(1, (int) $db->value("SELECT COUNT(*) FROM auth_tokens WHERE user_id = ? AND purpose = 'activation'", [$v['user_id']]));
    T::eq(1, (int) $db->value("SELECT COUNT(*) FROM email_outbox WHERE to_email = 'approve.me@example.test' AND template = 'volunteer_activation'"));
    T::assert((bool) $db->value("SELECT 1 FROM audit_logs WHERE action = 'application.approve' AND target_id = ?", [$aid]), 'audited');
});

T::test('workflow', 'approval of an email that matches an existing volunteer requires explicit confirmation (no duplicates)', function () {
    $db = T::db();
    $vid = T::volunteer('Returning Person');
    $email = $db->value('SELECT email FROM volunteers WHERE id = ?', [$vid]);
    T::post('/apply', apply_form(['email' => $email]));
    $aid = (int) $db->value('SELECT id FROM volunteer_applications WHERE email = ?', [$email]);
    $admin = T::user('approver2@example.test', ['organisation_admin']);
    T::actingAs($admin);
    T::post("/admin/applications/{$aid}/status", ['action' => 'approve']);
    T::eq('pending', $db->value('SELECT status FROM volunteer_applications WHERE id = ?', [$aid]), 'not approved without confirmation');
    T::post("/admin/applications/{$aid}/status", ['action' => 'approve', 'confirm_link' => '1']);
    T::eq((string) $vid, (string) $db->value('SELECT volunteer_id FROM volunteer_applications WHERE id = ?', [$aid]));
    T::eq(1, (int) $db->value('SELECT COUNT(*) FROM volunteers WHERE email = ?', [$email]), 'no duplicate volunteer');
});

T::test('workflow', 'reject: closed application cannot be reopened', function () {
    $db = T::db();
    T::post('/apply', apply_form(['email' => 'reject.me@example.test']));
    $aid = (int) $db->value("SELECT id FROM volunteer_applications WHERE email = 'reject.me@example.test'");
    T::actingAs(T::user('rejector@example.test', ['organisation_admin']));
    T::post("/admin/applications/{$aid}/status", ['action' => 'rejected', 'note' => 'Not now']);
    T::post("/admin/applications/{$aid}/status", ['action' => 'approve']);
    T::eq('rejected', $db->value('SELECT status FROM volunteer_applications WHERE id = ?', [$aid]));
});

T::test('workflow', 'service periods: create, overlap refused, end, return as new period; date change protected (IR-14)', function () {
    $db = T::db();
    $admin = T::user('periods.admin@example.test', ['organisation_admin']);
    T::actingAs($admin);
    $p = T::project('Return Project');
    $v = T::volunteer('Returner', null, 'approved');
    T::post("/projects/{$p}/periods", ['action' => 'create', 'volunteer_id' => $v, 'start_date' => '2026-01-05']);
    $per = (int) $db->value('SELECT id FROM project_assignments WHERE project_id = ? AND volunteer_id = ?', [$p, $v]);
    T::eq('active', $db->value('SELECT status FROM volunteers WHERE id = ?', [$v]), 'first assignment activates volunteer');
    T::post("/projects/{$p}/periods", ['action' => 'create', 'volunteer_id' => $v, 'start_date' => '2026-02-01']);
    T::eq(1, (int) $db->value('SELECT COUNT(*) FROM project_assignments WHERE project_id = ? AND volunteer_id = ?', [$p, $v]), 'overlap refused');
    $act = T::activity($p, '2026-03-10');
    T::roster($act, $p, $v, $per);
    T::post("/projects/{$p}/periods", ['action' => 'end', 'period_id' => $per, 'end_date' => '2026-03-01', 'status' => 'completed']);
    T::eq('active', $db->value('SELECT status FROM project_assignments WHERE id = ?', [$per]), 'cannot end before a rostered activity');
    T::post("/projects/{$p}/periods", ['action' => 'end', 'period_id' => $per, 'end_date' => '2026-03-31', 'status' => 'completed']);
    T::eq('completed', $db->value('SELECT status FROM project_assignments WHERE id = ?', [$per]));
    T::post("/projects/{$p}/periods", ['action' => 'create', 'volunteer_id' => $v, 'start_date' => '2026-06-01']);
    T::eq(2, (int) $db->value('SELECT COUNT(*) FROM project_assignments WHERE project_id = ? AND volunteer_id = ?', [$p, $v]), 'returning volunteer gets a new period');
    T::eq(1, (int) $db->value('SELECT COUNT(*) FROM activity_assignments WHERE project_assignment_id = ?', [$per]), 'history kept on old period');
});

T::test('workflow', 'supervisor ended not deleted; can be reappointed; ended supervisor loses access', function () {
    $db = T::db();
    $admin = T::user('sup.admin@example.test', ['organisation_admin']);
    $sup = T::user('sup.person@example.test', ['project_supervisor']);
    $p = T::project('Supervised Project');
    T::actingAs($admin);
    T::post("/projects/{$p}/supervisors", ['action' => 'assign', 'user_id' => $sup]);
    T::activity($p, '2026-07-01', $sup);
    T::post("/projects/{$p}/supervisors", ['action' => 'end', 'user_id' => $sup]);
    T::assert($db->value('SELECT ended_at FROM project_supervisors WHERE project_id = ? AND user_id = ?', [$p, $sup]) !== null, 'ended');
    T::actingAs($sup);
    T::eq(404, T::get("/projects/{$p}")->status, 'ended supervisor cannot see the project');
    T::actingAs($admin);
    T::post("/projects/{$p}/supervisors", ['action' => 'assign', 'user_id' => $sup]);
    T::actingAs($sup);
    T::eq(200, T::get("/projects/{$p}")->status, 'reappointed');
});

T::test('workflow', 'user admin: invite, role grant revokes sessions, owner protected, self-change refused', function () {
    $db = T::db();
    $admin = T::user('ua.admin@example.test', ['organisation_admin']);
    T::actingAs($admin);
    T::post('/admin/users', ['email' => 'new.staff@example.test', 'role' => 'project_supervisor']);
    $nid = (int) $db->value("SELECT id FROM users WHERE email = 'new.staff@example.test'");
    T::eq('invited', $db->value('SELECT status FROM users WHERE id = ?', [$nid]));
    T::post('/admin/users', ['email' => 'vol.via.invite@example.test', 'role' => 'volunteer']);
    T::eq(0, (int) $db->value("SELECT COUNT(*) FROM users WHERE email = 'vol.via.invite@example.test'"), 'volunteers not invitable as staff');
    $sv = (int) $db->value('SELECT session_version FROM users WHERE id = ?', [$nid]);
    T::post("/admin/users/{$nid}", ['action' => 'grant', 'role' => 'organisation_admin']);
    T::eq($sv + 1, (int) $db->value('SELECT session_version FROM users WHERE id = ?', [$nid]), 'role change ends sessions');
    $r = T::post('/admin/users/' . T::$ownerId, ['action' => 'deactivate']);
    T::eq(403, $r->status, 'owner cannot be deactivated (ST-31)');
    T::eq(403, T::post('/admin/users/' . T::$ownerId, ['action' => 'revoke', 'role' => 'organisation_admin'])->status, 'owner cannot be demoted');
    T::eq(403, T::post("/admin/users/{$admin}", ['action' => 'grant', 'role' => 'project_supervisor'])->status, 'no self role change (ST-30)');
    T::post("/admin/users/{$nid}", ['action' => 'delete', 'confirm1' => '1', 'confirm2' => '1']);
    T::eq(0, (int) $db->value('SELECT COUNT(*) FROM users WHERE id = ?', [$nid]), 'unused invitation deleted (W1)');
});

T::test('workflow', 'Org Admin cannot deactivate another Org Admin; owner can; ownership transfer owner-only (ST-32)', function () {
    $db = T::db();
    $oa1 = T::user('oa1@example.test', ['organisation_admin']);
    $oa2 = T::user('oa2@example.test', ['organisation_admin'], 'Oa2-Synthetic-Pass-9');
    T::actingAs($oa1);
    T::eq(403, T::post("/admin/users/{$oa2}", ['action' => 'deactivate'])->status);
    T::eq(403, T::post('/admin/ownership', ['target_id' => $oa1, 'password' => 'x', 'confirm1' => '1', 'confirm2' => '1'])->status);
    T::actingAs(T::$ownerId);
    T::post('/admin/ownership', ['target_id' => $oa2, 'password' => 'wrong', 'confirm1' => '1', 'confirm2' => '1']);
    T::eq((string) T::$ownerId, (string) $db->value('SELECT user_id FROM system_owner'), 'wrong password refused');
    T::post('/admin/ownership', ['target_id' => $oa2, 'password' => 'Owner-Synthetic-Pass-1', 'confirm1' => '1', 'confirm2' => '1']);
    T::eq((string) $oa2, (string) $db->value('SELECT user_id FROM system_owner'), 'transferred');
    T::assert((bool) $db->value('SELECT 1 FROM user_roles WHERE user_id = ? AND role_id = 1', [T::$ownerId]), 'former owner remains Org Admin');
    // transfer back for later tests
    $db->run('UPDATE system_owner SET user_id = ?', [T::$ownerId]);
});

T::test('workflow', 'erasure W4: two-person rule, personal data removed, history kept under anonymised id', function () {
    $db = T::db();
    T::post('/apply', apply_form(['email' => 'erase.me@example.test', 'full_name' => 'Erase Me']));
    $aid = (int) $db->value("SELECT id FROM volunteer_applications WHERE email = 'erase.me@example.test'");
    $oa = T::user('erase.oa@example.test', ['organisation_admin']);
    T::actingAs($oa);
    T::post("/admin/applications/{$aid}/status", ['action' => 'approve']);
    $vid = (int) $db->value("SELECT id FROM volunteers WHERE email = 'erase.me@example.test'");
    $p = T::project('Erasure Project');
    $per = T::period($p, $vid);
    $db->insert('time_logs', ['volunteer_id' => $vid, 'project_id' => $p, 'project_assignment_id' => $per, 'work_date' => '2026-02-02', 'minutes' => 60,
        'description' => 'Personal detail in free text', 'status' => 'approved', 'reviewed_by' => $oa, 'reviewed_at' => now_utc(), 'submitted_by' => $oa,
        'submitted_at' => now_utc(), 'created_at' => now_utc(), 'updated_at' => now_utc()]);
    T::post("/volunteers/{$vid}/notes", ['body' => 'Sensitive note', 'visibility' => 'admins']);
    T::post("/volunteers/{$vid}/erasure", ['action' => 'prepare', 'request_ref' => 'Synthetic request 1']);
    T::eq(403, T::post("/volunteers/{$vid}/erasure", ['action' => 'approve', 'confirm1' => '1', 'confirm2' => '1'])->status, 'Org Admin cannot approve (ST-39)');
    T::actingAs(T::$ownerId);
    T::post("/volunteers/{$vid}/erasure", ['action' => 'approve', 'confirm1' => '1', 'confirm2' => '1']);
    $v = $db->one('SELECT * FROM volunteers WHERE id = ?', [$vid]);
    T::eq("Erased volunteer #{$vid}", $v['full_name']);
    T::eq(null, $v['phone']);
    T::assert($v['anonymised_at'] !== null, 'anonymised');
    T::eq(0, (int) $db->value('SELECT COUNT(*) FROM volunteer_applications WHERE id = ?', [$aid]), 'application deleted');
    T::eq(0, (int) $db->value('SELECT COUNT(*) FROM volunteer_notes WHERE volunteer_id = ?', [$vid]), 'notes deleted');
    T::eq('[erased]', $db->value('SELECT description FROM time_logs WHERE volunteer_id = ?', [$vid]));
    T::eq(60, (int) $db->value("SELECT SUM(minutes) FROM time_logs WHERE volunteer_id = ? AND status = 'approved'", [$vid]), 'hours retained');
    T::eq("deleted-user-{$v['user_id']}@invalid", $db->value('SELECT email FROM users WHERE id = ?', [$v['user_id']]), 'linked account anonymised');
    T::eq(0, (int) $db->value("SELECT COUNT(*) FROM audit_logs WHERE details LIKE '%erase.me%' OR actor_email LIKE '%erase.me%'"), 'no personal data in audit');
});

T::test('workflow', 'erasure: preparer cannot approve their own request even as owner', function () {
    $db = T::db();
    $vid = T::volunteer('Self Prepared');
    T::actingAs(T::$ownerId);
    T::post("/volunteers/{$vid}/erasure", ['action' => 'prepare', 'request_ref' => 'Synthetic request 2']);
    T::post("/volunteers/{$vid}/erasure", ['action' => 'approve', 'confirm1' => '1', 'confirm2' => '1']);
    T::eq(null, $db->value('SELECT anonymised_at FROM volunteers WHERE id = ?', [$vid]), 'two-person rule enforced');
});

T::test('workflow', 'registration rate limit: 6th application from one connection within an hour is refused', function () {
    $db = T::db();
    $db->run("DELETE FROM rate_limit_hits");
    for ($i = 1; $i <= 6; $i++) {
        T::post('/apply', ['full_name' => 'Rate ' . $i, 'email' => "rate{$i}@example.test", 'expertise' => 'Other',
            'availability_type' => 'flexible', 'privacy_consent' => '1']);
    }
    T::eq(5, (int) $db->value("SELECT COUNT(*) FROM volunteer_applications WHERE email LIKE 'rate%@example.test'"));
});
