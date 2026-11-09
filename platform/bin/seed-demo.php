<?php
declare(strict_types=1);
/*
 * Synthetic demonstration data (clearly labelled). Refuses to run when APP_ENV=production.
 * Usage: php bin/seed-demo.php <password-for-demo-accounts>
 */
require __DIR__ . '/../src/bootstrap.php';
if (PHP_SAPI !== 'cli') { exit(1); }
if (env('APP_ENV') === 'production') { fwrite(STDERR, "Refusing to seed demo data in production.\n"); exit(1); }
$pw = $argv[1] ?? '';
if (strlen($pw) < 12) { fwrite(STDERR, "Give a demo password of at least 12 characters.\n"); exit(1); }
use App\Core\Database;
$db = Database::get();
if ($db->value("SELECT value FROM settings WHERE `key` = 'demo.seeded'")) { echo "Demo data already present.\n"; exit(0); }
$now = now_utc();
$hash = password_hash($pw, PASSWORD_DEFAULT);
$owner = (int) $db->value('SELECT user_id FROM system_owner');
$db->run("UPDATE users SET status = 'active', password_hash = ?, email_verified_at = ? WHERE id = ? AND status = 'invited'", [$hash, $now, $owner]);
$mkUser = function (string $email, int $role) use ($db, $hash, $now, $owner): int {
    $id = $db->insert('users', ['email' => $email, 'status' => 'active', 'password_hash' => $hash, 'session_version' => 1, 'email_verified_at' => $now,
        'created_by' => $owner, 'created_at' => $now, 'updated_at' => $now]);
    $db->insert('user_roles', ['user_id' => $id, 'role_id' => $role, 'granted_by' => $owner, 'granted_at' => $now]);
    return $id;
};
$oa = $mkUser('org.admin@example.test', 1);
$ps = $mkUser('supervisor@example.test', 2);
$day = fn(int $n) => (new DateTimeImmutable(today_local()))->modify("{$n} days")->format('Y-m-d');
$p1 = $db->insert('projects', ['name' => 'Community Health Champions (Demonstration Data)', 'description' => 'Sample record for demonstration only.', 'status' => 'active',
    'start_date' => $day(-120), 'created_by' => $oa, 'created_at' => $now, 'updated_at' => $now]);
$p2 = $db->insert('projects', ['name' => 'Digital Skills Drop-in (Demonstration Data)', 'description' => 'Sample record for demonstration only.', 'status' => 'active',
    'start_date' => $day(-60), 'created_by' => $oa, 'created_at' => $now, 'updated_at' => $now]);
$db->insert('project_supervisors', ['project_id' => $p1, 'user_id' => $ps, 'assigned_by' => $oa, 'assigned_at' => $now]);
$vols = [];
foreach (['Sample Volunteer A', 'Sample Volunteer B', 'Sample Volunteer C'] as $i => $name) {
    $u = $i === 0 ? $mkUser('volunteer@example.test', 3) : null;
    $vid = $db->insert('volunteers', ['user_id' => $u, 'full_name' => $name, 'email' => 'sample' . ($i + 1) . '@example.test', 'status' => 'active',
        'start_date' => $day(-100), 'created_at' => $now, 'updated_at' => $now]);
    $db->insert('volunteer_status_history', ['volunteer_id' => $vid, 'from_status' => null, 'to_status' => 'active', 'changed_by' => $oa, 'changed_at' => $now, 'reason' => 'Demonstration']);
    $db->run("INSERT INTO volunteer_skills (volunteer_id, skill_id, source, created_at) VALUES (?, ?, 'admin', ?)", [$vid, $i + 1, $now]);
    $vols[] = [$vid, $db->insert('project_assignments', ['project_id' => $p1, 'volunteer_id' => $vid, 'status' => 'active', 'start_date' => $day(-90),
        'assigned_by' => $oa, 'assigned_at' => $now, 'updated_at' => $now]), $u];
}
foreach ([-14, -7, 7] as $k => $off) {
    $a = $db->insert('activities', ['project_id' => $p1, 'title' => 'Health information session ' . ($k + 1) . ' (Sample)', 'activity_date' => $day($off),
        'start_time' => '10:00:00', 'end_time' => '12:30:00', 'venue' => 'Community Centre (sample venue)', 'lead_supervisor_id' => $ps,
        'status' => $off < 0 ? 'completed' : 'scheduled', 'created_by' => $oa, 'created_at' => $now, 'updated_at' => $now]);
    foreach ($vols as [$vid, $per]) {
        $db->insert('activity_assignments', ['activity_id' => $a, 'project_id' => $p1, 'volunteer_id' => $vid, 'project_assignment_id' => $per, 'assigned_by' => $ps, 'assigned_at' => $now]);
        if ($off < 0) {
            $db->insert('attendance', ['activity_id' => $a, 'volunteer_id' => $vid, 'status' => $vid % 3 ? 'present' : 'absent', 'recorded_by' => $ps, 'recorded_at' => $now, 'updated_at' => $now]);
            if ($vid % 3) {
                $db->insert('time_logs', ['volunteer_id' => $vid, 'project_id' => $p1, 'project_assignment_id' => $per, 'activity_id' => $a, 'work_date' => $day($off),
                    'minutes' => 150, 'description' => 'Sample session support', 'status' => $off === -14 ? 'approved' : 'pending', 'reviewed_by' => $off === -14 ? $ps : null,
                    'reviewed_at' => $off === -14 ? $now : null, 'submitted_by' => $owner, 'submitted_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
            }
        }
    }
}
$db->insert('volunteer_applications', ['reference' => 'DEMO000001', 'full_name' => 'Sample Applicant', 'email' => 'applicant@example.test', 'availability_type' => 'flexible',
    'motivation' => 'Demonstration application.', 'referral_source' => 'Website', 'privacy_consent' => 1, 'consent_version' => 'demo', 'consent_at' => $now,
    'status' => 'pending', 'submitted_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
$db->insert('announcements', ['title' => 'Welcome to the Volunteer Hub (Demonstration Data)', 'body' => 'This is a sample announcement.', 'audience' => 'all',
    'published_at' => $now, 'created_by' => $oa, 'created_at' => $now, 'updated_at' => $now]);
$db->insert('settings', ['key' => 'demo.seeded', 'value' => $now, 'updated_by' => null, 'updated_at' => $now]);
echo "Demo data created. Accounts (synthetic): " . $db->value('SELECT email FROM users WHERE id = ?', [$owner]) . ", org.admin@example.test, supervisor@example.test, volunteer@example.test\n";
