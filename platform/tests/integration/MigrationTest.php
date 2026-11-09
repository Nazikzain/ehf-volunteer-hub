<?php
declare(strict_types=1);

use App\Services\SupabaseImporter;
use Tests\T;

T::test('migration', 'dry run reports; errors block apply; apply imports only classified-genuine records; idempotent; reconciled', function () {
    $dir = APP_ROOT . '/tests/fixtures/supabase';
    $bad = ['approved_by' => 'Synthetic', 'approved_on' => '2026-11-09', 'genuine' => [
        'volunteer_projects' => ['11111111-0000-0000-0000-000000000001', '11111111-0000-0000-0000-000000000003'],
        'volunteer_tasks' => ['22222222-0000-0000-0000-000000000001', '22222222-0000-0000-0000-000000000002']]];
    $dry = SupabaseImporter::run($dir, $bad, false, T::$ownerId);
    T::eq('dry-run', $dry['mode']);
    T::eq(2, count($dry['errors']), 'invalid dates + orphan task');
    T::eq(0, (int) T::db()->value("SELECT COUNT(*) FROM projects WHERE legacy_uuid LIKE '11111111-%'"), 'dry run writes nothing');
    T::throws(RuntimeException::class, fn() => SupabaseImporter::run($dir, $bad, true, T::$ownerId));
    $ok = ['approved_by' => 'Synthetic', 'approved_on' => '2026-11-09', 'genuine' => [
        'volunteer_projects' => ['11111111-0000-0000-0000-000000000001'], 'volunteer_tasks' => ['22222222-0000-0000-0000-000000000001']]];
    $r = SupabaseImporter::run($dir, $ok, true, T::$ownerId);
    T::eq(['projects' => 1, 'tasks' => 1], $r['migrated']);
    T::assert($r['reconciliation_ok'], 'source = migrated + excluded + errors');
    T::eq(null, T::db()->value("SELECT assigned_assignment_id FROM tasks WHERE legacy_uuid = '22222222-0000-0000-0000-000000000001'"), 'assignee dropped');
    $again = SupabaseImporter::run($dir, $ok, true, T::$ownerId);
    T::eq(2, $again['skipped_existing'], 'idempotent');
    T::assert((bool) T::db()->value("SELECT 1 FROM audit_logs WHERE action = 'project.create' AND details LIKE '%supabase_migration%'"), 'audited');
});
