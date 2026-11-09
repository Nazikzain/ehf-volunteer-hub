<?php
declare(strict_types=1);

namespace Tests;

use App\Core\Csrf;
use App\Core\Database;
use App\Core\Env;
use App\Core\Installer;
use App\Core\Kernel;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Security\Auth;

/** Minimal, dependency-free test harness (decision TD-04). */
final class T
{
    public static array $tests = [];
    public static array $results = [];
    public static ?Kernel $kernel = null;
    public static int $ownerId = 0;
    private static int $assertions = 0;

    public static function test(string $suite, string $name, callable $fn): void
    {
        self::$tests[] = [$suite, $name, $fn];
    }

    public static function assert(bool $cond, string $msg = 'assertion failed'): void
    {
        self::$assertions++;
        if (!$cond) {
            throw new \AssertionError($msg);
        }
    }

    public static function eq(mixed $expected, mixed $actual, string $msg = ''): void
    {
        self::assert($expected === $actual, ($msg ? $msg . ': ' : '') . 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }

    /** Assert a database operation is rejected by the database itself. */
    public static function dbRejects(callable $fn, string $msg): void
    {
        try {
            $fn();
        } catch (\PDOException $e) {
            // Only genuine integrity violations count: FK 1451/1452, UNIQUE 1062, NOT NULL 1048,
            // CHECK 4025 (MariaDB) / 3819 (MySQL), SIGNAL 1644, ENUM/strict truncation 1265.
            $code = (int) ($e->errorInfo[1] ?? 0);
            if (!in_array($code, [1451, 1452, 1062, 1048, 4025, 3819, 1644, 1265], true)) {
                throw new \AssertionError("DB rejected '{$msg}' for an unexpected reason ({$code}): " . $e->getMessage());
            }
            self::$assertions++;
            return;
        }
        throw new \AssertionError("DB accepted invalid data: {$msg}");
    }

    public static function throws(string $class, callable $fn, string $msg = ''): \Throwable
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            if ($e instanceof $class) {
                self::$assertions++;
                return $e;
            }
            throw new \AssertionError("expected {$class}, got " . $e::class . ': ' . $e->getMessage());
        }
        throw new \AssertionError("expected {$class} {$msg}");
    }

    public static function db(): Database
    {
        return Database::get();
    }

    /** Fresh schema for the whole run (synthetic data only). */
    public static function setUpDatabase(): void
    {
        $name = 'ehf_test';
        Env::set('DB_NAME', $name);
        Env::set('APP_URL', 'http://test.local');
        Env::set('MAIL_TRANSPORT', 'disabled');
        $root = Database::connect((string) env('DB_HOST'), (int) env('DB_PORT', '3306'), 'information_schema', (string) env('DB_USER'), (string) env('DB_PASS'), env('DB_SOCKET'));
        $root->pdo->exec("DROP DATABASE IF EXISTS `{$name}`");
        $root->pdo->exec("CREATE DATABASE `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        Database::setInstance(null);
        Session::useArrayStore();
        $r = Installer::install(Database::get(), 'owner@example.test');
        self::$ownerId = $r['owner_id'];
        // Activate the owner with a known synthetic password.
        self::db()->run("UPDATE users SET status = 'active', password_hash = ?, email_verified_at = ? WHERE id = ?",
            [password_hash('Owner-Synthetic-Pass-1', PASSWORD_DEFAULT), now_utc(), self::$ownerId]);
        self::$kernel = new Kernel(require APP_ROOT . '/config/routes.php');
    }

    // ---- fixtures ----------------------------------------------------------------------------

    public static function user(string $email, array $roles, string $password = 'Synthetic-Pass-1234'): int
    {
        $db = self::db();
        $id = $db->insert('users', ['email' => $email, 'status' => 'active', 'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'session_version' => 1, 'email_verified_at' => now_utc(), 'created_at' => now_utc(), 'updated_at' => now_utc()]);
        $map = ['organisation_admin' => 1, 'project_supervisor' => 2, 'volunteer' => 3];
        foreach ($roles as $r) {
            $db->insert('user_roles', ['user_id' => $id, 'role_id' => $map[$r], 'granted_by' => self::$ownerId, 'granted_at' => now_utc()]);
        }
        return $id;
    }

    public static function volunteer(string $name, ?int $userId = null, string $status = 'active'): int
    {
        $email = strtolower(str_replace(' ', '.', $name)) . '.' . bin2hex(random_bytes(3)) . '@example.test';
        return self::db()->insert('volunteers', ['user_id' => $userId, 'full_name' => $name, 'email' => $email, 'status' => $status,
            'start_date' => '2026-01-01', 'created_at' => now_utc(), 'updated_at' => now_utc()]);
    }

    public static function project(string $name, string $status = 'active'): int
    {
        return self::db()->insert('projects', ['name' => $name, 'status' => $status, 'start_date' => '2026-01-01', 'created_by' => self::$ownerId,
            'created_at' => now_utc(), 'updated_at' => now_utc()]);
    }

    public static function supervise(int $projectId, int $userId, bool $ended = false): void
    {
        self::db()->insert('project_supervisors', ['project_id' => $projectId, 'user_id' => $userId, 'assigned_by' => self::$ownerId,
            'assigned_at' => now_utc(), 'ended_at' => $ended ? now_utc() : null, 'ended_by' => $ended ? self::$ownerId : null]);
    }

    public static function period(int $projectId, int $volunteerId, string $start = '2026-01-01', ?string $end = null, string $status = 'active'): int
    {
        return self::db()->insert('project_assignments', ['project_id' => $projectId, 'volunteer_id' => $volunteerId, 'status' => $status,
            'start_date' => $start, 'end_date' => $end, 'assigned_by' => self::$ownerId, 'assigned_at' => now_utc(), 'updated_at' => now_utc()]);
    }

    public static function activity(int $projectId, string $date, ?int $lead = null, string $status = 'scheduled'): int
    {
        return self::db()->insert('activities', ['project_id' => $projectId, 'title' => 'Session ' . $date, 'activity_date' => $date,
            'start_time' => '10:00:00', 'end_time' => '12:00:00', 'venue' => 'Community Hall (synthetic)', 'lead_supervisor_id' => $lead,
            'status' => $status, 'created_by' => self::$ownerId, 'created_at' => now_utc(), 'updated_at' => now_utc()]);
    }

    public static function roster(int $activityId, int $projectId, int $volunteerId, int $periodId): void
    {
        self::db()->insert('activity_assignments', ['activity_id' => $activityId, 'project_id' => $projectId, 'volunteer_id' => $volunteerId,
            'project_assignment_id' => $periodId, 'assigned_by' => self::$ownerId, 'assigned_at' => now_utc()]);
    }

    // ---- HTTP --------------------------------------------------------------------------------

    public static function actingAs(?int $userId): void
    {
        Session::useArrayStore();
        Auth::reset();
        if ($userId !== null) {
            $sv = (int) self::db()->value('SELECT session_version FROM users WHERE id = ?', [$userId]);
            Session::set('uid', $userId);
            Session::set('sv', $sv);
            Session::set('login_at', time());
            Session::set('last_seen', time());
        }
    }

    public static function get(string $path, array $query = [], array $headers = []): Response
    {
        return self::$kernel->handle(new Request('GET', $path, $query, [], ['REMOTE_ADDR' => '198.51.100.7'], $headers));
    }

    public static function post(string $path, array $data = [], bool $withCsrf = true, array $headers = []): Response
    {
        if ($withCsrf) {
            $data['_csrf'] = Csrf::token();
        }
        return self::$kernel->handle(new Request('POST', $path, [], $data, ['REMOTE_ADDR' => '198.51.100.7'], $headers));
    }

    public static function run(?string $filter = null): int
    {
        $start = microtime(true);
        $fail = 0;
        foreach (self::$tests as [$suite, $name, $fn]) {
            if ($filter && !str_contains($suite . ' ' . $name, $filter)) {
                continue;
            }
            self::actingAs(null);
            $t0 = microtime(true);
            try {
                $fn();
                $ok = true;
                $err = '';
            } catch (\Throwable $e) {
                $ok = false;
                $err = $e::class . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine();
                $fail++;
                if (self::db()->inTransaction()) {
                    self::db()->pdo->rollBack();
                }
            }
            self::$results[] = ['suite' => $suite, 'name' => $name, 'ok' => $ok, 'error' => $err, 'ms' => round((microtime(true) - $t0) * 1000)];
            echo ($ok ? '  PASS ' : '  FAIL ') . "[{$suite}] {$name}" . ($ok ? '' : "\n         {$err}") . "\n";
        }
        $n = count(self::$results);
        printf("\n%d tests, %d passed, %d failed, %d assertions, %.1fs\n", $n, $n - $fail, $fail, self::$assertions, microtime(true) - $start);
        return $fail;
    }
}
