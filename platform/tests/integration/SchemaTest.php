<?php
declare(strict_types=1);

use Tests\T;

T::test('schema', '34 tables, expected FK/CHECK/trigger counts, utf8mb4_bin audit table', function () {
    $db = T::db();
    T::eq(34, (int) $db->value('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()'));
    T::eq(68, (int) $db->value('SELECT COUNT(*) FROM information_schema.referential_constraints WHERE constraint_schema = DATABASE()'));
    T::assert((int) $db->value('SELECT COUNT(*) FROM information_schema.check_constraints WHERE constraint_schema = DATABASE()') >= 32, 'checks');
    T::eq(4, (int) $db->value('SELECT COUNT(*) FROM information_schema.triggers WHERE trigger_schema = DATABASE()'));
    T::eq('utf8mb4_bin', $db->value("SELECT table_collation FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'audit_logs'"));
});

T::test('schema', 'system_owner singleton: a second Primary Admin row is impossible', function () {
    $u = T::user('second.owner@example.test', ['organisation_admin']);
    T::dbRejects(fn() => T::db()->insert('system_owner', ['singleton' => 'owner', 'user_id' => $u, 'since' => now_utc()]), 'duplicate owner');
    T::dbRejects(fn() => T::db()->run("INSERT INTO system_owner (singleton, user_id, since) VALUES ('other', ?, ?)", [$u, now_utc()]), 'other enum value');
    T::dbRejects(fn() => T::db()->run('DELETE FROM users WHERE id = ?', [T::$ownerId]), 'owner account deletable');
});

T::test('schema', 'CHECK constraints: users, applications, scores, minutes, link_path, outbox, certificates', function () {
    $db = T::db();
    T::dbRejects(fn() => $db->insert('users', ['email' => 'nopw@example.test', 'status' => 'active', 'session_version' => 1, 'created_at' => now_utc(), 'updated_at' => now_utc()]), 'active without password');
    $base = ['reference' => 'CHKTEST001', 'full_name' => 'X', 'email' => 'chk@example.test', 'availability_type' => 'flexible', 'consent_version' => 'v', 'consent_at' => now_utc(),
        'submitted_at' => now_utc(), 'created_at' => now_utc(), 'updated_at' => now_utc()];
    T::dbRejects(fn() => $db->insert('volunteer_applications', $base + ['privacy_consent' => 0, 'status' => 'pending']), 'no consent');
    T::dbRejects(fn() => $db->insert('volunteer_applications', $base + ['privacy_consent' => 1, 'status' => 'approved', 'reviewed_by' => T::$ownerId]), 'approved without volunteer');
    T::dbRejects(fn() => $db->insert('volunteer_applications', $base + ['privacy_consent' => 1, 'status' => 'rejected']), 'rejected without reviewer');
    $v = T::volunteer('Check Volunteer');
    T::dbRejects(fn() => $db->insert('performance_reviews', ['volunteer_id' => $v, 'reviewer_id' => T::$ownerId, 'review_date' => '2026-01-01', 'reliability' => 6,
        'teamwork' => 3, 'communication' => 3, 'quality' => 3, 'created_at' => now_utc(), 'updated_at' => now_utc()]), 'score 6');
    $u = T::user('notif@example.test', ['volunteer']);
    T::dbRejects(fn() => $db->insert('notifications', ['user_id' => $u, 'type' => 't', 'title' => 't', 'link_path' => 'https://evil.example', 'created_at' => now_utc()]), 'absolute link');
    T::dbRejects(fn() => $db->insert('notifications', ['user_id' => $u, 'type' => 't', 'title' => 't', 'link_path' => '//evil.example', 'created_at' => now_utc()]), 'protocol-relative link');
    T::dbRejects(fn() => $db->insert('email_outbox', ['to_email' => 'x@example.test', 'template' => 't', 'subject' => 's', 'body' => 'secret', 'status' => 'sent',
        'available_at' => now_utc(), 'created_at' => now_utc()]), 'sent with body');
    T::dbRejects(fn() => $db->insert('certificates', ['volunteer_id' => $v, 'status' => 'issued', 'created_by' => T::$ownerId, 'created_at' => now_utc(), 'updated_at' => now_utc()]), 'issued without reference');
    T::dbRejects(fn() => $db->run("INSERT INTO audit_logs (seq, canon_version, occurred_at, recorded_at, source, action, outcome, request_id, details, key_id, prev_hash, row_hash)
        VALUES (999999, 1, NOW(6), NOW(6), 'direct', 'x', 'success', 'r', '{bad', 1, REPEAT('a',32), REPEAT('b',32))"), 'invalid JSON details');
});

T::test('schema', 'one open application per email; closed applications do not block reapplying', function () {
    $db = T::db();
    $mk = fn(string $ref, string $status) => $db->insert('volunteer_applications', ['reference' => $ref, 'full_name' => 'Dup', 'email' => 'dup@example.test',
        'availability_type' => 'flexible', 'privacy_consent' => 1, 'consent_version' => 'v', 'consent_at' => now_utc(), 'status' => $status,
        'reviewed_by' => $status === 'pending' ? null : T::$ownerId, 'submitted_at' => now_utc(), 'created_at' => now_utc(), 'updated_at' => now_utc()]);
    $mk('DUPREF0001', 'rejected');
    $mk('DUPREF0002', 'pending');
    T::dbRejects(fn() => $mk('DUPREF0003', 'under_review'), 'second open application');
});

T::test('schema', 'service periods: one active period per volunteer/project; ended periods need an end date', function () {
    $p = T::project('Periods Project');
    $v = T::volunteer('Period Volunteer');
    T::period($p, $v, '2026-01-01', '2026-02-01', 'completed');
    T::period($p, $v, '2026-03-01');
    T::dbRejects(fn() => T::period($p, $v, '2026-04-01'), 'second active period');
    T::dbRejects(fn() => T::period($p, $v, '2026-05-01', null, 'withdrawn'), 'withdrawn without end date');
});

T::test('schema', 'composite FKs enforce project boundaries (roster, attendance, lead, tasks, time logs)', function () {
    $db = T::db();
    $pA = T::project('Boundary A');
    $pB = T::project('Boundary B');
    $v = T::volunteer('Boundary Volunteer');
    $perA = T::period($pA, $v);
    $actB = T::activity($pB, '2026-06-01');
    T::dbRejects(fn() => T::roster($actB, $pB, $v, $perA), 'roster on project B using a project A period');
    T::dbRejects(fn() => T::roster($actB, $pA, $v, $perA), 'activity of B claimed as A');
    $sup = T::user('boundary.sup@example.test', ['project_supervisor']);
    T::supervise($pA, $sup);
    T::dbRejects(fn() => T::activity($pB, '2026-06-02', $sup), 'lead supervisor of another project');
    T::dbRejects(fn() => $db->insert('attendance', ['activity_id' => $actB, 'volunteer_id' => $v, 'status' => 'present', 'recorded_by' => T::$ownerId,
        'recorded_at' => now_utc(), 'updated_at' => now_utc()]), 'attendance without roster');
    T::dbRejects(fn() => $db->insert('tasks', ['project_id' => $pB, 'title' => 't', 'assigned_assignment_id' => $perA, 'status' => 'pending',
        'created_by' => T::$ownerId, 'created_at' => now_utc(), 'updated_at' => now_utc()]), 'task assigned to a period on another project');
    $tl = ['volunteer_id' => $v, 'project_id' => $pA, 'project_assignment_id' => $perA, 'work_date' => '2026-06-01', 'minutes' => 60, 'description' => 'x',
        'submitted_by' => T::$ownerId, 'submitted_at' => now_utc(), 'created_at' => now_utc(), 'updated_at' => now_utc()];
    T::dbRejects(fn() => $db->insert('time_logs', ['activity_id' => $actB] + $tl), 'time log citing activity of another project');
    $actA = T::activity($pA, '2026-06-03');
    T::dbRejects(fn() => $db->insert('time_logs', ['activity_id' => $actA] + $tl), 'time log for an activity the volunteer was not rostered on');
    T::dbRejects(fn() => $db->insert('time_logs', ['minutes' => 0] + $tl), 'zero minutes');
    T::dbRejects(fn() => $db->insert('time_logs', ['minutes' => 1441] + $tl), 'over 24h');
});

T::test('schema', 'time logs: live uniqueness per activity, resubmission allowed after rejection, not both activity and task', function () {
    $db = T::db();
    $p = T::project('TL Project');
    $v = T::volunteer('TL Volunteer');
    $per = T::period($p, $v);
    $a = T::activity($p, '2026-06-10');
    T::roster($a, $p, $v, $per);
    $task = $db->insert('tasks', ['project_id' => $p, 'title' => 'T', 'status' => 'pending', 'created_by' => T::$ownerId, 'created_at' => now_utc(), 'updated_at' => now_utc()]);
    $base = ['volunteer_id' => $v, 'project_id' => $p, 'project_assignment_id' => $per, 'work_date' => '2026-06-10', 'minutes' => 120, 'description' => 'Helped',
        'submitted_by' => T::$ownerId, 'submitted_at' => now_utc(), 'created_at' => now_utc(), 'updated_at' => now_utc()];
    $first = $db->insert('time_logs', $base + ['activity_id' => $a]);
    T::dbRejects(fn() => $db->insert('time_logs', $base + ['activity_id' => $a]), 'second live log for same activity');
    T::dbRejects(fn() => $db->insert('time_logs', $base + ['activity_id' => $a, 'task_id' => $task]), 'both activity and task');
    $db->run("UPDATE time_logs SET status = 'rejected', reviewed_by = ?, reviewed_at = ? WHERE id = ?", [T::$ownerId, now_utc(), $first]);
    $second = $db->insert('time_logs', $base + ['activity_id' => $a, 'resubmission_of_id' => $first]);
    T::assert($second > 0, 'resubmission accepted');
    T::dbRejects(fn() => $db->insert('time_logs', $base + ['task_id' => $task, 'resubmission_of_id' => $first]), 'second resubmission of same log');
    T::dbRejects(fn() => $db->run("UPDATE time_logs SET status = 'voided' WHERE id = ?", [$second]), 'void without void fields');
});

T::test('schema', 'triggers: approved time logs immutable; audit log append-only', function () {
    $db = T::db();
    $p = T::project('Trig Project');
    $v = T::volunteer('Trig Volunteer');
    $per = T::period($p, $v);
    $id = $db->insert('time_logs', ['volunteer_id' => $v, 'project_id' => $p, 'project_assignment_id' => $per, 'work_date' => '2026-06-11', 'minutes' => 90,
        'description' => 'Work', 'status' => 'approved', 'reviewed_by' => T::$ownerId, 'reviewed_at' => now_utc(), 'submitted_by' => T::$ownerId,
        'submitted_at' => now_utc(), 'created_at' => now_utc(), 'updated_at' => now_utc()]);
    T::dbRejects(fn() => $db->run('UPDATE time_logs SET minutes = 300 WHERE id = ?', [$id]), 'changing approved minutes');
    T::dbRejects(fn() => $db->run("UPDATE time_logs SET status = 'pending' WHERE id = ?", [$id]), 'un-approving');
    $db->run("UPDATE time_logs SET description = '[erased]' WHERE id = ?", [$id]); // erasure redaction allowed
    $db->run("UPDATE time_logs SET status = 'voided', voided_by = ?, voided_at = ?, void_reason = 'wrong date' WHERE id = ?", [T::$ownerId, now_utc(), $id]);
    T::eq('voided', $db->value('SELECT status FROM time_logs WHERE id = ?', [$id]));
    T::dbRejects(fn() => $db->run('UPDATE audit_logs SET action = ? WHERE seq = 1', ['tampered']), 'audit update');
    T::dbRejects(fn() => $db->run('DELETE FROM audit_logs WHERE seq = 1'), 'audit delete');
    T::dbRejects(fn() => $db->run("DELETE FROM audit_chain WHERE singleton = 'head'"), 'chain head delete');
});

T::test('schema', 'history protection: RESTRICT keys stop deleting volunteers/users with history', function () {
    $db = T::db();
    $p = T::project('Restrict Project');
    $v = T::volunteer('Restrict Volunteer');
    T::period($p, $v);
    T::dbRejects(fn() => $db->run('DELETE FROM volunteers WHERE id = ?', [$v]), 'volunteer with a period');
    T::dbRejects(fn() => $db->run('DELETE FROM projects WHERE id = ?', [$p]), 'project with periods');
    T::dbRejects(fn() => $db->run('DELETE FROM users WHERE id = ?', [T::$ownerId]), 'user who created records');
});
