<?php
declare(strict_types=1);

/*
 * Design Review Rev3 §8.3 security test matrix, ST-01..ST-40, executed through the real HTTP kernel.
 * Roles: PA (Primary Admin), OA, PSa (active supervisor of P), PSx (supervises Q only; supervision of P ended),
 * Vown (volunteer acting on own record), Voth (volunteer acting on another's record), Anon.
 * Every 403/404 must also be audited as access.denied.
 */

use App\Core\Response;
use Tests\T;

final class Fx
{
    public static function build(): array
    {
        $db = T::db();
        $s = bin2hex(random_bytes(3));
        $P = T::project("P {$s}");
        $Q = T::project("Q {$s}");
        $oa = T::user("oa.{$s}@example.test", ['organisation_admin']);
        $psa = T::user("psa.{$s}@example.test", ['project_supervisor']);
        $psx = T::user("psx.{$s}@example.test", ['project_supervisor']);
        T::supervise($P, $psa);
        T::supervise($Q, $psx);
        T::supervise($P, $psx, true);
        $uOwn = T::user("vown.{$s}@example.test", ['volunteer']);
        $uOth = T::user("voth.{$s}@example.test", ['volunteer']);
        $vOwn = T::volunteer("Own {$s}", $uOwn);
        $vOth = T::volunteer("Other {$s}", $uOth);
        $perOwn = T::period($P, $vOwn);
        $perOth = T::period($P, $vOth);
        $past = (new DateTimeImmutable(today_local()))->modify('-2 days')->format('Y-m-d');
        $actPast = T::activity($P, $past, $psa);
        T::roster($actPast, $P, $vOwn, $perOwn);
        T::roster($actPast, $P, $vOth, $perOth);
        $tl = fn(int $vid, int $per, int $uid, string $status) => $db->insert('time_logs', ['volunteer_id' => $vid, 'project_id' => $P,
            'project_assignment_id' => $per, 'work_date' => $past, 'minutes' => 60, 'description' => 'Synthetic', 'status' => $status,
            'reviewed_by' => $status === 'pending' ? null : $oa, 'reviewed_at' => $status === 'pending' ? null : now_utc(),
            'submitted_by' => $uid, 'submitted_at' => now_utc(), 'created_at' => now_utc(), 'updated_at' => now_utc()]);
        $cert = $db->insert('certificates', ['volunteer_id' => $vOwn, 'reference' => "EHF-T-{$s}", 'status' => 'issued', 'required_hours' => 1,
            'approved_minutes_at_issue' => 60, 'issued_at' => now_utc(), 'approved_by' => $oa, 'approved_at' => now_utc(), 'created_by' => $oa,
            'created_at' => now_utc(), 'updated_at' => now_utc()]);
        $app = $db->insert('volunteer_applications', ['reference' => strtoupper(substr("A{$s}XXXXXXXX", 0, 10)), 'full_name' => 'Applicant', 'email' => "app.{$s}@example.test",
            'availability_type' => 'flexible', 'privacy_consent' => 1, 'consent_version' => 'v', 'consent_at' => now_utc(), 'status' => 'pending',
            'submitted_at' => now_utc(), 'created_at' => now_utc(), 'updated_at' => now_utc()]);
        $roles = ['PA' => T::$ownerId, 'OA' => $oa, 'PSa' => $psa, 'PSx' => $psx, 'Vown' => $uOwn, 'Voth' => $uOth, 'Anon' => null];
        return compact('P', 'Q', 'oa', 'psa', 'psx', 'uOwn', 'uOth', 'vOwn', 'vOth', 'perOwn', 'perOth', 'actPast', 'past', 'tl', 'cert', 'app', 'roles', 's');
    }
}

/** Run one request as a role and check status (+ audited denial). $expect: int or [ints]. */
function as_role(?int $uid, string $method, string $path, array $data = [], array $headers = []): Response
{
    T::actingAs($uid);
    return $method === 'GET' ? T::get($path, $data, $headers) : T::post($path, $data, true, $headers);
}

function expect_status(string $case, string $role, Response $r, int|array $expect): void
{
    $ok = in_array($r->status, (array) $expect, true);
    T::assert($ok, "{$case} as {$role}: expected " . implode('/', (array) $expect) . ", got {$r->status}");
    if (in_array($r->status, [403, 404], true) && $role !== 'Anon') {
        T::assert((bool) T::db()->value("SELECT 1 FROM audit_logs WHERE action IN ('access.denied','csrf.rejected') AND request_id = ?",
            [App\Audit\AuditLogger::$requestId]), "{$case} as {$role}: denial not audited");
    }
}

/** Matrix helper: $m = [role => expected status]. */
function matrix(string $case, array $fx, string $method, string|callable $path, array|callable $data, array $m): void
{
    foreach ($m as $role => $expect) {
        $p = is_callable($path) ? $path($role) : $path;
        $d = is_callable($data) ? $data($role) : $data;
        expect_status($case, $role, as_role($fx['roles'][$role], $method, $p, $d), $expect);
    }
}

const OK = 200; const RD = 303; const L = 303; const F = 403; const NF = 404;

T::test('security', 'ST-01 view application list', function () {
    $fx = Fx::build();
    matrix('ST-01', $fx, 'GET', '/admin/applications', [], ['PA' => OK, 'OA' => OK, 'PSa' => F, 'PSx' => F, 'Vown' => F, 'Voth' => F, 'Anon' => L]);
});

T::test('security', 'ST-02 open application by sequential id in URL', function () {
    $fx = Fx::build();
    matrix('ST-02', $fx, 'GET', '/admin/applications/' . $fx['app'], [], ['PA' => OK, 'OA' => OK, 'PSa' => F, 'PSx' => F, 'Vown' => F, 'Voth' => F, 'Anon' => L]);
});

T::test('security', 'ST-03 approve application', function () {
    $fx = Fx::build();
    matrix('ST-03', $fx, 'POST', '/admin/applications/' . $fx['app'] . '/status', ['action' => 'under_review'], ['PSa' => F, 'PSx' => F, 'Vown' => F, 'Voth' => F, 'Anon' => L]);
    T::eq('pending', T::db()->value('SELECT status FROM volunteer_applications WHERE id = ?', [$fx['app']]), 'unchanged by denied roles');
    matrix('ST-03', $fx, 'POST', '/admin/applications/' . $fx['app'] . '/status', ['action' => 'approve'], ['OA' => RD]);
    T::eq('approved', T::db()->value('SELECT status FROM volunteer_applications WHERE id = ?', [$fx['app']]));
});

T::test('security', 'ST-04 view volunteer profile on project P', function () {
    $fx = Fx::build();
    $path = fn($role) => '/volunteers/' . ($role === 'Vown' ? $fx['vOwn'] : $fx['vOth']);
    matrix('ST-04', $fx, 'GET', $path, [], ['PA' => OK, 'OA' => OK, 'PSa' => OK, 'PSx' => NF, 'Voth' => RD, 'Anon' => L]);
    // Vown requesting their own id is redirected to /me (own view); requesting another volunteer is 404
    expect_status('ST-04', 'Vown', as_role($fx['uOwn'], 'GET', '/volunteers/' . $fx['vOth']), NF);
    expect_status('ST-04', 'Vown', as_role($fx['uOwn'], 'GET', '/me'), OK);
});

T::test('security', 'ST-05 edit volunteer contact details', function () {
    $fx = Fx::build();
    $data = ['action' => 'details', 'full_name' => 'Changed', 'email' => 'changed.' . $fx['s'] . '@example.test', 'phone' => '', 'postcode' => '', 'preferred_language' => ''];
    matrix('ST-05', $fx, 'POST', '/volunteers/' . $fx['vOth'], $data, ['PSa' => F, 'PSx' => F, 'Vown' => NF, 'Anon' => L]);
    T::assert(T::db()->value('SELECT full_name FROM volunteers WHERE id = ?', [$fx['vOth']]) !== 'Changed', 'not changed by denied roles');
    matrix('ST-05', $fx, 'POST', '/volunteers/' . $fx['vOth'], $data, ['OA' => RD]);
    T::eq('Changed', T::db()->value('SELECT full_name FROM volunteers WHERE id = ?', [$fx['vOth']]));
    expect_status('ST-05', 'Vown', as_role($fx['uOwn'], 'POST', '/me', ['phone' => '07700 900456', 'postcode' => 'M2 2BB', 'preferred_language' => 'Arabic']), RD);
    T::eq('07700 900456', T::db()->value('SELECT phone FROM volunteers WHERE id = ?', [$fx['vOwn']]), 'own permitted fields');
});

T::test('security', 'ST-06 volunteer cannot edit own name/email; others 404', function () {
    $fx = Fx::build();
    $data = ['action' => 'details', 'full_name' => 'Self Renamed', 'email' => 'x@example.test'];
    expect_status('ST-06', 'Vown', as_role($fx['uOwn'], 'POST', '/volunteers/' . $fx['vOwn'], $data), F);
    expect_status('ST-06', 'Voth', as_role($fx['uOth'], 'POST', '/volunteers/' . $fx['vOwn'], $data), NF);
    T::assert(T::db()->value('SELECT full_name FROM volunteers WHERE id = ?', [$fx['vOwn']]) !== 'Self Renamed', 'unchanged');
});

T::test('security', 'ST-07 view project P', function () {
    $fx = Fx::build();
    matrix('ST-07', $fx, 'GET', '/projects/' . $fx['P'], [], ['PA' => OK, 'OA' => OK, 'PSa' => OK, 'PSx' => NF, 'Vown' => OK, 'Anon' => L]);
    expect_status('ST-07', 'Voth', as_role($fx['uOth'], 'GET', '/projects/' . $fx['Q']), NF);
});

T::test('security', 'ST-08 create project', function () {
    $fx = Fx::build();
    matrix('ST-08', $fx, 'POST', '/projects', ['name' => 'Denied ' . $fx['s'], 'status' => 'planned'], ['PSa' => F, 'PSx' => F, 'Vown' => F, 'Voth' => F, 'Anon' => L]);
    T::eq(0, (int) T::db()->value('SELECT COUNT(*) FROM projects WHERE name = ?', ['Denied ' . $fx['s']]));
    matrix('ST-08', $fx, 'POST', '/projects', ['name' => 'Allowed ' . $fx['s'], 'status' => 'planned'], ['PA' => RD, 'OA' => RD]);
    T::eq(2, (int) T::db()->value('SELECT COUNT(*) FROM projects WHERE name = ?', ['Allowed ' . $fx['s']]));
});

T::test('security', 'ST-09 assign supervisor to P', function () {
    $fx = Fx::build();
    $data = ['action' => 'assign', 'user_id' => $fx['psx']];
    matrix('ST-09', $fx, 'POST', '/projects/' . $fx['P'] . '/supervisors', $data, ['PSa' => F, 'PSx' => F, 'Vown' => F, 'Voth' => F, 'Anon' => L]);
    T::assert(T::db()->value('SELECT ended_at FROM project_supervisors WHERE project_id = ? AND user_id = ?', [$fx['P'], $fx['psx']]) !== null, 'still ended');
    matrix('ST-09', $fx, 'POST', '/projects/' . $fx['P'] . '/supervisors', $data, ['OA' => RD]);
    T::eq(null, T::db()->value('SELECT ended_at FROM project_supervisors WHERE project_id = ? AND user_id = ?', [$fx['P'], $fx['psx']]));
});

T::test('security', 'ST-10 create service period on P', function () {
    $fx = Fx::build();
    $v = T::volunteer('Period Target ' . $fx['s'], null, 'approved');
    $data = ['action' => 'create', 'volunteer_id' => $v, 'start_date' => today_local()];
    matrix('ST-10', $fx, 'POST', '/projects/' . $fx['P'] . '/periods', $data, ['PSx' => NF, 'Vown' => F, 'Voth' => F, 'Anon' => L]);
    T::eq(0, (int) T::db()->value('SELECT COUNT(*) FROM project_assignments WHERE volunteer_id = ?', [$v]));
    matrix('ST-10', $fx, 'POST', '/projects/' . $fx['P'] . '/periods', $data, ['PSa' => RD]);
    T::eq(1, (int) T::db()->value('SELECT COUNT(*) FROM project_assignments WHERE volunteer_id = ?', [$v]));
});

T::test('security', 'ST-11 schedule activity on P', function () {
    $fx = Fx::build();
    $data = fn($role) => ['project_id' => $fx['P'], 'title' => "Act {$role} {$fx['s']}", 'activity_date' => today_local(), 'start_time' => '10:00', 'end_time' => '11:00', 'venue' => 'Hall'];
    matrix('ST-11', $fx, 'POST', '/activities', $data, ['PA' => RD, 'OA' => RD, 'PSa' => RD, 'PSx' => NF, 'Vown' => F, 'Voth' => F, 'Anon' => L]);
    T::eq(3, (int) T::db()->value("SELECT COUNT(*) FROM activities WHERE title LIKE ?", ["Act %{$fx['s']}"]));
});

T::test('security', 'ST-12 roster a volunteer whose period has ended / does not cover the date: rejected', function () {
    $fx = Fx::build();
    $future = (new DateTimeImmutable(today_local()))->modify('+10 days')->format('Y-m-d');
    $act = T::activity($fx['P'], $future);
    $v = T::volunteer('Ended ' . $fx['s']);
    T::period($fx['P'], $v, '2026-01-01', today_local(), 'completed');
    foreach (['PA' => RD, 'OA' => RD, 'PSa' => RD, 'PSx' => NF, 'Vown' => F, 'Voth' => F, 'Anon' => L] as $role => $exp) {
        expect_status('ST-12', $role, as_role($fx['roles'][$role], 'POST', "/activities/{$act}", ['action' => 'roster_add', 'volunteer_id' => $v]), $exp);
    }
    T::eq(0, (int) T::db()->value('SELECT COUNT(*) FROM activity_assignments WHERE activity_id = ? AND volunteer_id = ?', [$act, $v]), 'never rostered');
});

T::test('security', 'ST-13 record attendance on P', function () {
    $fx = Fx::build();
    $data = ['action' => 'attendance', 'attendance' => [$fx['vOwn'] => 'present']];
    matrix('ST-13', $fx, 'POST', '/activities/' . $fx['actPast'], $data, ['PSx' => NF, 'Vown' => F, 'Voth' => F, 'Anon' => L]);
    T::eq(0, (int) T::db()->value('SELECT COUNT(*) FROM attendance WHERE activity_id = ?', [$fx['actPast']]));
    matrix('ST-13', $fx, 'POST', '/activities/' . $fx['actPast'], $data, ['PSa' => RD]);
    T::eq('present', T::db()->value('SELECT status FROM attendance WHERE activity_id = ? AND volunteer_id = ?', [$fx['actPast'], $fx['vOwn']]));
});

T::test('security', 'ST-14 volunteer cannot change attendance (own or other)', function () {
    $fx = Fx::build();
    // Documented interpretation: volunteers hold no attendance-write permission at all, so §8.1 rule 9 gives 403
    // for both cases (the response is identical whatever the id, so nothing is disclosed).
    expect_status('ST-14', 'Vown', as_role($fx['uOwn'], 'POST', '/activities/' . $fx['actPast'], ['action' => 'attendance', 'attendance' => [$fx['vOwn'] => 'present']]), F);
    expect_status('ST-14', 'Voth', as_role($fx['uOth'], 'POST', '/activities/' . $fx['actPast'], ['action' => 'attendance', 'attendance' => [$fx['vOwn'] => 'absent']]), F);
    T::eq(0, (int) T::db()->value('SELECT COUNT(*) FROM attendance WHERE activity_id = ?', [$fx['actPast']]));
});

T::test('security', 'ST-15 submit time log (volunteers only)', function () {
    $fx = Fx::build();
    $data = ['kind' => 'activity', 'activity_id' => $fx['actPast'], 'hours' => '1.5', 'description' => 'Helped'];
    matrix('ST-15', $fx, 'POST', '/my/hours', $data, ['PA' => F, 'OA' => F, 'PSa' => F, 'PSx' => F, 'Anon' => L]);
    matrix('ST-15', $fx, 'POST', '/my/hours', $data, ['Vown' => RD]);
    T::eq(90, (int) T::db()->value('SELECT minutes FROM time_logs WHERE volunteer_id = ? AND activity_id = ?', [$fx['vOwn'], $fx['actPast']]));
});

T::test('security', 'ST-16 second live log for the same activity is rejected', function () {
    $fx = Fx::build();
    $data = ['kind' => 'activity', 'activity_id' => $fx['actPast'], 'hours' => '1', 'description' => 'Helped'];
    as_role($fx['uOwn'], 'POST', '/my/hours', $data);
    as_role($fx['uOwn'], 'POST', '/my/hours', $data);
    T::eq(1, (int) T::db()->value('SELECT COUNT(*) FROM time_logs WHERE volunteer_id = ? AND activity_id = ?', [$fx['vOwn'], $fx['actPast']]));
});

T::test('security', 'ST-17 reviewer cannot approve their own submission', function () {
    $fx = Fx::build();
    $id = ($fx['tl'])($fx['vOwn'], $fx['perOwn'], $fx['psa'], 'pending');
    expect_status('ST-17', 'PSa', as_role($fx['psa'], 'POST', "/hours/{$id}/review", ['decision' => 'approve']), F);
    T::eq('pending', T::db()->value('SELECT status FROM time_logs WHERE id = ?', [$id]));
});

T::test('security', 'ST-18 approve time log on P', function () {
    $fx = Fx::build();
    $id = ($fx['tl'])($fx['vOth'], $fx['perOth'], $fx['uOth'], 'pending');
    matrix('ST-18', $fx, 'POST', "/hours/{$id}/review", ['decision' => 'approve'], ['PSx' => NF, 'Vown' => F, 'Voth' => F, 'Anon' => L]);
    T::eq('pending', T::db()->value('SELECT status FROM time_logs WHERE id = ?', [$id]));
    matrix('ST-18', $fx, 'POST', "/hours/{$id}/review", ['decision' => 'approve'], ['PSa' => RD]);
    T::eq('approved', T::db()->value('SELECT status FROM time_logs WHERE id = ?', [$id]));
});

T::test('security', 'ST-19 approved time log cannot be edited (must be voided)', function () {
    $fx = Fx::build();
    $id = ($fx['tl'])($fx['vOwn'], $fx['perOwn'], $fx['uOwn'], 'approved');
    expect_status('ST-19', 'Vown', as_role($fx['uOwn'], 'POST', "/my/hours/{$id}", ['hours' => '8', 'description' => 'more']), RD);
    expect_status('ST-19', 'Voth', as_role($fx['uOth'], 'POST', "/my/hours/{$id}", ['hours' => '8', 'description' => 'more']), NF);
    T::eq(60, (int) T::db()->value('SELECT minutes FROM time_logs WHERE id = ?', [$id]), 'unchanged');
    T::dbRejects(fn() => T::db()->run('UPDATE time_logs SET minutes = 480 WHERE id = ?', [$id]), 'direct DB edit of approved hours');
});

T::test('security', 'ST-20 void approved time log on P', function () {
    $fx = Fx::build();
    $id = ($fx['tl'])($fx['vOth'], $fx['perOth'], $fx['uOth'], 'approved');
    matrix('ST-20', $fx, 'POST', "/hours/{$id}/review", ['decision' => 'void', 'comment' => 'Wrong date'], ['PSx' => NF, 'Vown' => F, 'Voth' => F, 'Anon' => L]);
    T::eq('approved', T::db()->value('SELECT status FROM time_logs WHERE id = ?', [$id]));
    matrix('ST-20', $fx, 'POST', "/hours/{$id}/review", ['decision' => 'void', 'comment' => 'Wrong date'], ['PSa' => RD]);
    T::eq('voided', T::db()->value('SELECT status FROM time_logs WHERE id = ?', [$id]));
});

T::test('security', 'ST-21 write performance review on P', function () {
    $fx = Fx::build();
    $data = ['project_id' => $fx['P'], 'review_date' => today_local(), 'reliability' => 4, 'teamwork' => 4, 'communication' => 4, 'quality' => 4];
    matrix('ST-21', $fx, 'POST', '/volunteers/' . $fx['vOth'] . '/reviews', $data, ['PSx' => NF, 'Vown' => F, 'Voth' => F, 'Anon' => L]);
    T::eq(0, (int) T::db()->value('SELECT COUNT(*) FROM performance_reviews WHERE volunteer_id = ?', [$fx['vOth']]));
    matrix('ST-21', $fx, 'POST', '/volunteers/' . $fx['vOth'] . '/reviews', $data, ['PA' => RD, 'OA' => RD, 'PSa' => RD]);
    T::eq(3, (int) T::db()->value('SELECT COUNT(*) FROM performance_reviews WHERE volunteer_id = ?', [$fx['vOth']]));
});

T::test('security', 'ST-22 volunteer reads own review only if shared; another volunteer 404', function () {
    $fx = Fx::build();
    $mk = fn(int $shared, string $txt) => T::db()->insert('performance_reviews', ['volunteer_id' => $fx['vOwn'], 'project_id' => $fx['P'], 'reviewer_id' => $fx['psa'],
        'review_date' => today_local(), 'reliability' => 5, 'teamwork' => 5, 'communication' => 5, 'quality' => 5, 'achievements' => $txt,
        'shared_with_volunteer' => $shared, 'created_at' => now_utc(), 'updated_at' => now_utc()]);
    $mk(1, 'SHARED-TEXT');
    $mk(0, 'PRIVATE-TEXT');
    $r = as_role($fx['uOwn'], 'GET', '/my/reviews');
    T::eq(200, $r->status);
    T::assert(str_contains($r->body, 'SHARED-TEXT') && !str_contains($r->body, 'PRIVATE-TEXT'), 'only shared review visible');
    expect_status('ST-22', 'Voth', as_role($fx['uOth'], 'GET', '/volunteers/' . $fx['vOwn'] . '/reviews'), NF);
    expect_status('ST-22', 'Vown', as_role($fx['uOwn'], 'GET', '/volunteers/' . $fx['vOwn'] . '/reviews'), RD);
});

T::test('security', 'ST-23 read volunteer notes', function () {
    $fx = Fx::build();
    $db = T::db();
    $db->insert('volunteer_notes', ['volunteer_id' => $fx['vOth'], 'author_user_id' => $fx['oa'], 'project_id' => null, 'visibility' => 'admins', 'body' => 'ADMIN-ONLY-NOTE', 'created_at' => now_utc(), 'updated_at' => now_utc()]);
    $db->insert('volunteer_notes', ['volunteer_id' => $fx['vOth'], 'author_user_id' => $fx['psa'], 'project_id' => $fx['P'], 'visibility' => 'project', 'body' => 'PROJECT-NOTE', 'created_at' => now_utc(), 'updated_at' => now_utc()]);
    $oa = as_role($fx['oa'], 'GET', '/volunteers/' . $fx['vOth']);
    T::assert(str_contains($oa->body, 'ADMIN-ONLY-NOTE') && str_contains($oa->body, 'PROJECT-NOTE'), 'admin sees all notes');
    $ps = as_role($fx['psa'], 'GET', '/volunteers/' . $fx['vOth']);
    T::assert(!str_contains($ps->body, 'ADMIN-ONLY-NOTE') && str_contains($ps->body, 'PROJECT-NOTE'), 'supervisor sees project notes only');
    expect_status('ST-23', 'PSx', as_role($fx['psx'], 'GET', '/volunteers/' . $fx['vOth']), NF);
    $me = as_role($fx['uOth'], 'GET', '/me');
    T::assert(!str_contains($me->body, 'NOTE'), 'volunteer never sees notes');
});

T::test('security', 'ST-24 download own certificate', function () {
    $fx = Fx::build();
    $path = '/certificates/' . $fx['cert'];
    matrix('ST-24', $fx, 'GET', $path, [], ['PA' => OK, 'OA' => OK, 'PSa' => F, 'PSx' => F, 'Vown' => OK, 'Voth' => NF, 'Anon' => L]);
});

T::test('security', 'ST-25 export CSV is scoped exactly like the screens', function () {
    $fx = Fx::build();
    ($fx['tl'])($fx['vOwn'], $fx['perOwn'], $fx['uOwn'], 'approved');
    $vQ = T::volunteer('Q Volunteer ' . $fx['s']);
    $perQ = T::period($fx['Q'], $vQ);
    T::db()->insert('time_logs', ['volunteer_id' => $vQ, 'project_id' => $fx['Q'], 'project_assignment_id' => $perQ, 'work_date' => $fx['past'], 'minutes' => 30,
        'description' => 'Q work', 'status' => 'approved', 'reviewed_by' => $fx['oa'], 'reviewed_at' => now_utc(), 'submitted_by' => $fx['oa'],
        'submitted_at' => now_utc(), 'created_at' => now_utc(), 'updated_at' => now_utc()]);
    $q = ['kind' => 'hours', 'type' => 'year', 'value' => substr($fx['past'], 0, 4)];
    $oa = as_role($fx['oa'], 'GET', '/reports/export', $q);
    T::assert(str_contains($oa->body, 'Own ' . $fx['s']) && str_contains($oa->body, 'Q Volunteer ' . $fx['s']), 'admin export includes both projects');
    $psa = as_role($fx['psa'], 'GET', '/reports/export', $q);
    T::assert(str_contains($psa->body, 'Own ' . $fx['s']) && !str_contains($psa->body, 'Q Volunteer ' . $fx['s']), 'supervisor export limited to P');
    $psx = as_role($fx['psx'], 'GET', '/reports/export', $q);
    T::assert(!str_contains($psx->body, 'Own ' . $fx['s']) && str_contains($psx->body, 'Q Volunteer ' . $fx['s']), 'PSx export limited to Q');
    expect_status('ST-25', 'PSa', as_role($fx['psa'], 'GET', '/reports/export', $q + ['project' => $fx['Q']]), NF);
    expect_status('ST-25', 'Vown', as_role($fx['uOwn'], 'GET', '/reports/export', $q), F);
    T::assert((bool) T::db()->value("SELECT 1 FROM audit_logs WHERE action = 'export.personal_data'"), 'export audited');
});

T::test('security', 'ST-26 dashboards are scoped', function () {
    $fx = Fx::build();
    $psx = as_role($fx['psx'], 'GET', '/dashboard');
    T::eq(200, $psx->status);
    T::assert(!str_contains($psx->body, 'P ' . $fx['s'] . '<') && str_contains($psx->body, 'Q ' . $fx['s']), 'PSx dashboard shows only Q');
    $v = as_role($fx['uOwn'], 'GET', '/dashboard');
    T::assert(str_contains($v->body, 'My approved hours') && !str_contains($v->body, 'Pending applications'), 'volunteer sees own summary only');
    expect_status('ST-26', 'Anon', as_role(null, 'GET', '/dashboard'), L);
});

T::test('security', 'ST-27 AJAX request with a forged out-of-scope id returns JSON 404', function () {
    $fx = Fx::build();
    foreach (['PSa' => $fx['psa'], 'Vown' => $fx['uOwn']] as $role => $uid) {
        T::actingAs($uid);
        $r = T::get('/projects/' . $fx['Q'], [], ['accept' => 'application/json']);
        expect_status('ST-27', $role, $r, NF);
        T::assert(str_starts_with($r->headers['Content-Type'], 'application/json'), 'json response');
    }
    T::actingAs(null);
    T::eq(401, T::get('/projects/' . $fx['Q'], [], ['accept' => 'application/json'])->status);
});

T::test('security', 'ST-28 invite user', function () {
    $fx = Fx::build();
    $data = fn($role) => ['email' => "inv.{$role}.{$fx['s']}@example.test", 'role' => 'project_supervisor'];
    matrix('ST-28', $fx, 'POST', '/admin/users', $data, ['PA' => RD, 'OA' => RD, 'PSa' => F, 'PSx' => F, 'Vown' => F, 'Voth' => F, 'Anon' => L]);
    T::eq(2, (int) T::db()->value('SELECT COUNT(*) FROM users WHERE email LIKE ?', ["inv.%.{$fx['s']}@example.test"]));
});

T::test('security', 'ST-29 grant Organisation Admin role', function () {
    $fx = Fx::build();
    $target = T::user('grant.' . $fx['s'] . '@example.test', ['project_supervisor']);
    matrix('ST-29', $fx, 'POST', "/admin/users/{$target}", ['action' => 'grant', 'role' => 'organisation_admin'], ['PSa' => F, 'PSx' => F, 'Vown' => F, 'Voth' => F, 'Anon' => L]);
    T::assert(!T::db()->value('SELECT 1 FROM user_roles WHERE user_id = ? AND role_id = 1', [$target]), 'not granted');
    matrix('ST-29', $fx, 'POST', "/admin/users/{$target}", ['action' => 'grant', 'role' => 'organisation_admin'], ['OA' => RD]);
    T::assert((bool) T::db()->value('SELECT 1 FROM user_roles WHERE user_id = ? AND role_id = 1', [$target]), 'granted');
});

T::test('security', 'ST-30 nobody can change their own roles', function () {
    $fx = Fx::build();
    foreach (['PA' => T::$ownerId, 'OA' => $fx['oa']] as $role => $uid) {
        expect_status('ST-30', $role, as_role($uid, 'POST', "/admin/users/{$uid}", ['action' => 'grant', 'role' => 'project_supervisor']), F);
    }
    foreach (['PSa' => $fx['psa'], 'PSx' => $fx['psx'], 'Vown' => $fx['uOwn']] as $role => $uid) {
        expect_status('ST-30', $role, as_role($uid, 'POST', "/admin/users/{$uid}", ['action' => 'grant', 'role' => 'organisation_admin']), F);
    }
    expect_status('ST-30', 'Anon', as_role(null, 'POST', '/admin/users/1', ['action' => 'grant', 'role' => 'organisation_admin']), L);
    T::assert(!T::db()->value('SELECT 1 FROM user_roles WHERE user_id = ? AND role_id = 1', [$fx['psa']]), 'no escalation');
});

T::test('security', 'ST-31 Primary Admin cannot be deactivated, demoted or anonymised (not even by themselves)', function () {
    $fx = Fx::build();
    foreach (['deactivate', 'anonymise'] as $a) {
        matrix('ST-31', $fx, 'POST', '/admin/users/' . T::$ownerId, ['action' => $a, 'confirm1' => '1', 'confirm2' => '1'],
            ['PA' => F, 'OA' => F, 'PSa' => F, 'PSx' => F, 'Vown' => F, 'Voth' => F, 'Anon' => L]);
    }
    matrix('ST-31', $fx, 'POST', '/admin/users/' . T::$ownerId, ['action' => 'revoke', 'role' => 'organisation_admin'], ['OA' => F]);
    T::eq('active', T::db()->value('SELECT status FROM users WHERE id = ?', [T::$ownerId]));
});

T::test('security', 'ST-32 ownership transfer is owner-only, with re-authentication and two confirmations', function () {
    $fx = Fx::build();
    $data = ['target_id' => $fx['oa'], 'password' => 'Synthetic-Pass-1234', 'confirm1' => '1', 'confirm2' => '1'];
    matrix('ST-32', $fx, 'POST', '/admin/ownership', $data, ['OA' => F, 'PSa' => F, 'PSx' => F, 'Vown' => F, 'Voth' => F, 'Anon' => L]);
    expect_status('ST-32', 'PA', as_role(T::$ownerId, 'POST', '/admin/ownership', ['target_id' => $fx['oa'], 'password' => 'Owner-Synthetic-Pass-1', 'confirm1' => '1']), RD);
    T::eq((string) T::$ownerId, (string) T::db()->value('SELECT user_id FROM system_owner'), 'one confirmation is not enough');
});

T::test('security', 'ST-33 sessions end after deactivation, role change and password change', function () {
    $fx = Fx::build();
    foreach ([fn($id) => T::db()->run("UPDATE users SET status = 'deactivated', session_version = session_version + 1 WHERE id = ?", [$id]),
              fn($id) => App\Core\Database::get()->run('UPDATE users SET session_version = session_version + 1 WHERE id = ?', [$id])] as $change) {
        $u = T::user('sess.' . bin2hex(random_bytes(3)) . '@example.test', ['project_supervisor']);
        T::actingAs($u);
        T::eq(200, T::get('/dashboard')->status);
        $change($u);
        T::eq(303, T::get('/dashboard')->status);
    }
    // role change through the real admin endpoint
    $u = T::user('sess.role.' . $fx['s'] . '@example.test', ['project_supervisor']);
    T::actingAs($u);
    $store = App\Core\Session::get('sv');
    as_role($fx['oa'], 'POST', "/admin/users/{$u}", ['action' => 'revoke', 'role' => 'project_supervisor']);
    T::actingAs(null);
    App\Core\Session::set('uid', $u); App\Core\Session::set('sv', $store); App\Core\Session::set('login_at', time()); App\Core\Session::set('last_seen', time());
    T::eq(303, T::get('/dashboard')->status, 'old session rejected after role change');
});

T::test('security', 'ST-34 state-changing POST without CSRF token is rejected for every role', function () {
    $fx = Fx::build();
    foreach ($fx['roles'] as $role => $uid) {
        T::actingAs($uid);
        $r = T::post('/projects', ['name' => 'CSRF ' . $fx['s']], false);
        T::eq(403, $r->status, "ST-34 {$role}");
    }
    T::eq(0, (int) T::db()->value('SELECT COUNT(*) FROM projects WHERE name = ?', ['CSRF ' . $fx['s']]));
});

T::test('security', 'ST-35 storage, .env and fallback files are not web-reachable', function () {
    foreach (['/storage/logs/app.log', '/.env', '/storage/audit-fallback/x.jsonl', '/config/permissions.php', '/src/bootstrap.php'] as $p) {
        T::eq(404, T::get($p)->status, $p);
    }
    $public = array_map('basename', glob(APP_ROOT . '/public/{,.}*', GLOB_BRACE));
    T::assert(!in_array('.env', $public, true) && !in_array('storage', $public, true), 'nothing sensitive inside public/');
    T::assert(str_contains((string) file_get_contents(APP_ROOT . '/public/.htaccess'), 'Require all denied'), 'deny rule for sensitive extensions');
    T::assert(str_contains((string) file_get_contents(APP_ROOT . '/storage/.htaccess'), 'Require all denied'), 'storage deny file for web-root hosts');
});

T::test('security', 'ST-36 view audit log', function () {
    $fx = Fx::build();
    matrix('ST-36', $fx, 'GET', '/admin/audit', [], ['PA' => OK, 'OA' => OK, 'PSa' => F, 'PSx' => F, 'Vown' => F, 'Voth' => F, 'Anon' => L]);
});

T::test('security', 'ST-37 reconcile fallback file', function () {
    $fx = Fx::build();
    matrix('ST-37', $fx, 'POST', '/admin/audit/reconcile', [], ['PA' => RD, 'OA' => RD, 'PSa' => F, 'PSx' => F, 'Vown' => F, 'Voth' => F, 'Anon' => L]);
});

T::test('security', 'ST-38 audit archiving reserved to the Primary Admin (manual runbook; permission-level check)', function () {
    $fx = Fx::build();
    foreach (['PA' => true, 'OA' => false, 'PSa' => false, 'Vown' => false] as $role => $exp) {
        T::actingAs($fx['roles'][$role]);
        App\Security\Auth::loadFromSession();
        T::eq($exp, App\Security\Gate::allows('audit.archive'), "ST-38 {$role}");
    }
});

T::test('security', 'ST-39 approve volunteer erasure: Primary Admin only', function () {
    $fx = Fx::build();
    as_role($fx['oa'], 'POST', '/volunteers/' . $fx['vOth'] . '/erasure', ['action' => 'prepare', 'request_ref' => 'Synthetic']);
    matrix('ST-39', $fx, 'POST', '/volunteers/' . $fx['vOth'] . '/erasure', ['action' => 'approve', 'confirm1' => '1', 'confirm2' => '1'],
        ['OA' => F, 'PSa' => F, 'PSx' => F, 'Vown' => F, 'Voth' => F, 'Anon' => L]);
    T::eq(null, T::db()->value('SELECT anonymised_at FROM volunteers WHERE id = ?', [$fx['vOth']]));
    matrix('ST-39', $fx, 'POST', '/volunteers/' . $fx['vOth'] . '/erasure', ['action' => 'approve', 'confirm1' => '1', 'confirm2' => '1'], ['PA' => RD]);
    T::assert(T::db()->value('SELECT anonymised_at FROM volunteers WHERE id = ?', [$fx['vOth']]) !== null, 'erased by PA');
});

T::test('security', 'ST-40 duplicate open application: generic response, nothing disclosed', function () {
    T::db()->run('DELETE FROM rate_limit_hits');
    $form = ['full_name' => 'Dup Person', 'email' => 'st40@example.test', 'expertise' => 'Other', 'availability_type' => 'flexible', 'privacy_consent' => '1'];
    $a = T::post('/apply', $form);
    $b = T::post('/apply', $form);
    T::eq($a->headers['Location'], $b->headers['Location']);
    T::eq(1, (int) T::db()->value("SELECT COUNT(*) FROM volunteer_applications WHERE email = 'st40@example.test'"));
});
