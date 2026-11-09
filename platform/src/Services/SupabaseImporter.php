<?php
declare(strict_types=1);

namespace App\Services;

use App\Audit\AuditLogger;
use App\Core\Database;

/**
 * Supabase -> MySQL migration for the record classes that may be genuine (Architecture Rev3 §4, §7):
 * volunteer_projects -> projects, volunteer_tasks -> tasks. Input: CSV exports from the Supabase dashboard
 * (read-only), plus a classification file signed off by EHF listing the GENUINE source ids. Anything not
 * listed is excluded (the 6 applications are test data [C-1]; personal-data tables are not imported).
 * Default is a dry run; --apply writes in one transaction. Idempotent through legacy_uuid.
 */
final class SupabaseImporter
{
    private const PROJECT_STATUS = ['planned' => 'planned', 'active' => 'active', 'completed' => 'completed', 'cancelled' => 'cancelled'];
    private const TASK_STATUS = ['pending' => 'pending', 'in_progress' => 'in_progress', 'completed' => 'completed', 'cancelled' => 'cancelled'];

    public static function readCsv(string $file): array
    {
        if (!is_file($file)) {
            return [];
        }
        $fh = fopen($file, 'r');
        $head = fgetcsv($fh, 0, ',', '"', '\\');
        $head[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $head[0]);
        $rows = [];
        while (($r = fgetcsv($fh, 0, ',', '"', '\\')) !== false) {
            if ($r === [null]) { continue; }
            $rows[] = array_combine($head, array_pad($r, count($head), null));
        }
        fclose($fh);
        return $rows;
    }

    private static function date(?string $v): ?string
    {
        $v = trim((string) $v);
        if ($v === '') { return null; }
        $d = substr($v, 0, 10);
        return ProjectService::isDate($d) ? $d : 'INVALID';
    }

    /** @return array report */
    public static function run(string $sourceDir, array $classification, bool $apply, int $actorUserId): array
    {
        $genuine = $classification['genuine'] ?? [];
        $gp = array_flip($genuine['volunteer_projects'] ?? []);
        $gt = array_flip($genuine['volunteer_tasks'] ?? []);
        $projects = self::readCsv(rtrim($sourceDir, '/') . '/volunteer_projects.csv');
        $tasks = self::readCsv(rtrim($sourceDir, '/') . '/volunteer_tasks.csv');
        $db = Database::get();
        $report = ['mode' => $apply ? 'apply' : 'dry-run', 'source' => ['volunteer_projects' => count($projects), 'volunteer_tasks' => count($tasks)],
            'migrated' => ['projects' => 0, 'tasks' => 0], 'skipped_existing' => 0, 'excluded' => [], 'errors' => [], 'warnings' => []];
        $plan = ['projects' => [], 'tasks' => []];
        $names = [];
        foreach ($projects as $p) {
            $id = (string) $p['id'];
            if (!isset($gp[$id])) { $report['excluded'][] = ['volunteer_projects', $id, 'not classified as genuine']; continue; }
            $status = self::PROJECT_STATUS[$p['project_status'] ?? ''] ?? null;
            $start = self::date($p['start_date'] ?? null);
            $end = self::date($p['end_date'] ?? null);
            $name = trim((string) ($p['name'] ?? ''));
            if ($name === '' || $status === null || $start === 'INVALID' || $end === 'INVALID' || ($start && $end && $end < $start)) {
                $report['errors'][] = ['volunteer_projects', $id, 'invalid name/status/dates'];
                continue;
            }
            $key = mb_strtolower($name);
            if (isset($names[$key]) || $db->value('SELECT 1 FROM projects WHERE name = ? AND (legacy_uuid IS NULL OR legacy_uuid <> ?)', [$name, $id])) {
                $report['warnings'][] = ['volunteer_projects', $id, 'duplicate project name; review before cutover'];
            }
            $names[$key] = true;
            $plan['projects'][$id] = ['name' => mb_substr($name, 0, 150), 'description' => ($p['description'] ?? '') ?: null, 'status' => $status,
                'start_date' => $start, 'end_date' => $end, 'legacy_uuid' => $id, 'created_at' => self::ts($p['created_at'] ?? null)];
        }
        foreach ($tasks as $t) {
            $id = (string) $t['id'];
            if (!isset($gt[$id])) { $report['excluded'][] = ['volunteer_tasks', $id, 'not classified as genuine']; continue; }
            $pid = (string) ($t['project_id'] ?? '');
            if (!isset($plan['projects'][$pid]) && !$db->value('SELECT 1 FROM projects WHERE legacy_uuid = ?', [$pid])) {
                $report['errors'][] = ['volunteer_tasks', $id, 'orphan: its project is not migrated']; continue;
            }
            $status = self::TASK_STATUS[$t['task_status'] ?? ''] ?? null;
            $due = self::date($t['due_date'] ?? null);
            if (trim((string) ($t['title'] ?? '')) === '' || $status === null || $due === 'INVALID') {
                $report['errors'][] = ['volunteer_tasks', $id, 'invalid title/status/due date']; continue;
            }
            if (($t['assigned_to'] ?? '') !== '') {
                $report['warnings'][] = ['volunteer_tasks', $id, 'assignee dropped (volunteer profiles are not migrated); task imported unassigned'];
            }
            $plan['tasks'][$id] = ['project_legacy' => $pid, 'title' => mb_substr(trim((string) $t['title']), 0, 180), 'description' => ($t['description'] ?? '') ?: null,
                'status' => $status, 'due_date' => $due, 'legacy_uuid' => $id, 'created_at' => self::ts($t['created_at'] ?? null)];
        }
        if (!$apply) {
            $report['would_migrate'] = ['projects' => count($plan['projects']), 'tasks' => count($plan['tasks'])];
            return $report;
        }
        if ($report['errors']) {
            throw new \RuntimeException('Migration refused: resolve the ' . count($report['errors']) . ' error(s) shown in the dry run first.');
        }
        $db->transaction(function (Database $db) use ($plan, $actorUserId, &$report) {
            $now = now_utc();
            foreach ($plan['projects'] as $legacy => $p) {
                if ($db->value('SELECT 1 FROM projects WHERE legacy_uuid = ?', [$legacy])) { $report['skipped_existing']++; continue; }
                $id = $db->insert('projects', ['name' => $p['name'], 'description' => $p['description'], 'status' => $p['status'], 'start_date' => $p['start_date'],
                    'end_date' => $p['end_date'], 'legacy_uuid' => $legacy, 'created_by' => $actorUserId, 'created_at' => $p['created_at'] ?? $now, 'updated_at' => $now]);
                AuditLogger::record('project.create', 'success', 'project', $id, ['source' => 'supabase_migration']);
                $report['migrated']['projects']++;
            }
            foreach ($plan['tasks'] as $legacy => $t) {
                if ($db->value('SELECT 1 FROM tasks WHERE legacy_uuid = ?', [$legacy])) { $report['skipped_existing']++; continue; }
                $pid = (int) $db->value('SELECT id FROM projects WHERE legacy_uuid = ?', [$t['project_legacy']]);
                $id = $db->insert('tasks', ['project_id' => $pid, 'title' => $t['title'], 'description' => $t['description'], 'status' => $t['status'],
                    'due_date' => $t['due_date'], 'completed_at' => $t['status'] === 'completed' ? ($t['created_at'] ?? $now) : null, 'legacy_uuid' => $legacy,
                    'created_by' => $actorUserId, 'created_at' => $t['created_at'] ?? $now, 'updated_at' => $now]);
                AuditLogger::record('task.create', 'success', 'task', $id, ['source' => 'supabase_migration']);
                $report['migrated']['tasks']++;
            }
        });
        // Reconciliation: source = migrated + skipped + excluded + errors
        $report['reconciliation_ok'] = ($report['source']['volunteer_projects'] + $report['source']['volunteer_tasks'])
            === ($report['migrated']['projects'] + $report['migrated']['tasks'] + $report['skipped_existing'] + count($report['excluded']) + count($report['errors']));
        return $report;
    }

    private static function ts(?string $v): ?string
    {
        $v = trim((string) $v);
        if ($v === '') { return null; }
        try {
            return (new \DateTimeImmutable($v))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return null;
        }
    }
}
