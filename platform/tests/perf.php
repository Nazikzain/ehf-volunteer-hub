<?php
declare(strict_types=1);
// Performance test on a separate database (ehf_perf) with synthetic volume. Usage: php tests/perf.php
require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/Harness.php';
use Tests\T;
use App\Core\Database;
use App\Core\Env;

Env::set('DB_NAME', 'ehf_perf');
$root = Database::connect((string) env('DB_HOST'), (int) env('DB_PORT', '3306'), 'information_schema', (string) env('DB_USER'), (string) env('DB_PASS'));
$root->pdo->exec('DROP DATABASE IF EXISTS ehf_perf');
$root->pdo->exec('CREATE DATABASE ehf_perf CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
Database::setInstance(null);
App\Core\Session::useArrayStore();
Env::set('EXPORT_DIR', sys_get_temp_dir() . '/ehf-perf-exports');
$owner = App\Core\Installer::install(Database::get(), 'perf.owner@example.test')['owner_id'];
$db = Database::get();
$db->run("UPDATE users SET status='active', password_hash=? WHERE id=?", [password_hash('Perf-Synthetic-Pass-1', PASSWORD_DEFAULT), $owner]);
T::$ownerId = $owner;
T::$kernel = new App\Core\Kernel(require APP_ROOT . '/config/routes.php');
$res = [];
$t = microtime(true);
$db->transaction(function (Database $db) use ($owner) {
    $now = now_utc();
    for ($p = 1; $p <= 40; $p++) {
        $db->insert('projects', ['name' => "Perf project {$p}", 'status' => 'active', 'start_date' => '2025-01-01', 'created_by' => $owner, 'created_at' => $now, 'updated_at' => $now]);
    }
    for ($v = 1; $v <= 2000; $v++) {
        $db->insert('volunteers', ['full_name' => "Perf Volunteer {$v}", 'email' => "perf{$v}@example.test", 'status' => 'active', 'start_date' => '2025-01-01', 'created_at' => $now, 'updated_at' => $now]);
        $db->run("INSERT INTO volunteer_skills (volunteer_id, skill_id, source, created_at) VALUES (?, ?, 'admin', ?)", [$v, ($v % 10) + 1, $now]);
        $db->insert('project_assignments', ['project_id' => (($v - 1) % 40) + 1, 'volunteer_id' => $v, 'status' => 'active', 'start_date' => '2025-01-01', 'assigned_by' => $owner, 'assigned_at' => $now, 'updated_at' => $now]);
    }
    $act = 0;
    for ($p = 1; $p <= 40; $p++) {
        for ($k = 0; $k < 100; $k++) {
            $date = (new DateTimeImmutable('2025-01-06'))->modify('+' . ($k * 6) . ' days')->format('Y-m-d');
            $act = $db->insert('activities', ['project_id' => $p, 'title' => "Session {$k}", 'activity_date' => $date, 'start_time' => '10:00:00', 'end_time' => '12:00:00',
                'venue' => 'Hall', 'status' => $date < today_local() ? 'completed' : 'scheduled', 'created_by' => $owner, 'created_at' => $now, 'updated_at' => $now]);
            if ($date >= today_local()) { continue; }
            for ($j = 0; $j < 8; $j++) {
                $v = $p + 40 * (($k + $j) % 50);
                if ($v > 2000) { continue; }
                $db->run('INSERT IGNORE INTO activity_assignments (activity_id, project_id, volunteer_id, project_assignment_id, assigned_by, assigned_at) VALUES (?, ?, ?, ?, ?, ?)', [$act, $p, $v, $v, $owner, $now]);
                $db->run("INSERT IGNORE INTO attendance (activity_id, volunteer_id, status, recorded_by, recorded_at, updated_at) VALUES (?, ?, 'present', ?, ?, ?)", [$act, $v, $owner, $now, $now]);
                $db->run("INSERT IGNORE INTO time_logs (volunteer_id, project_id, project_assignment_id, activity_id, work_date, minutes, description, status, reviewed_by, reviewed_at, submitted_by, submitted_at, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, 120, 'perf', 'approved', ?, ?, ?, ?, ?, ?)", [$v, $p, $v, $act, $date, $owner, $now, $owner, $now, $now, $now]);
            }
        }
    }
});
$res['seed_seconds'] = round(microtime(true) - $t, 1);
$res['rows'] = $db->one('SELECT (SELECT COUNT(*) FROM volunteers) v, (SELECT COUNT(*) FROM activities) a, (SELECT COUNT(*) FROM time_logs) t, (SELECT COUNT(*) FROM attendance) att');
$t = microtime(true);
for ($i = 0; $i < 20000; $i++) {
    App\Audit\AuditLogger::record('project.update', 'success', 'project', ($i % 40) + 1, ['fields' => ['name']]);
}
$res['audit_writes_per_second'] = (int) round(20000 / (microtime(true) - $t));
$time = function (string $label, callable $fn) use (&$res) { $t = microtime(true); $fn(); $res['ms'][$label] = (int) round((microtime(true) - $t) * 1000); };
T::actingAs($owner);
$time('GET /dashboard (admin)', fn() => T::get('/dashboard'));
$time('GET /volunteers (page 1)', fn() => T::get('/volunteers'));
$time('GET /volunteers?q=Volunteer 19', fn() => T::get('/volunteers', ['q' => 'Volunteer 19']));
$time('GET /volunteers/1000', fn() => T::get('/volunteers/1000'));
$time('GET /projects', fn() => T::get('/projects'));
$time('GET /activities', fn() => T::get('/activities'));
$time('GET /reports (year)', fn() => T::get('/reports', ['type' => 'year', 'value' => '2025']));
$time('GET /reports/export hours (year)', fn() => T::get('/reports/export', ['kind' => 'hours', 'type' => 'year', 'value' => '2025']));
$time('GET /admin/integrity', fn() => T::get('/admin/integrity'));
$time('Audit verification (all entries)', fn() => App\Audit\AuditVerifier::verify(false));
$res['audit_entries'] = (int) $db->value('SELECT COUNT(*) FROM audit_logs');
$res['verify_result'] = App\Audit\AuditVerifier::verify(false)['result'];
echo json_encode($res, JSON_PRETTY_PRINT), "\n";
@mkdir(__DIR__ . '/output', 0700, true);
file_put_contents(__DIR__ . '/output/perf.json', json_encode($res, JSON_PRETTY_PRINT));
$root->pdo->exec('DROP DATABASE ehf_perf');
