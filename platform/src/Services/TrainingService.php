<?php
declare(strict_types=1);

namespace App\Services;

use App\Audit\AuditLogger;
use App\Core\Database;
use App\Core\ValidationException;
use App\Security\Auth;
use App\Security\Gate;

/** Training types (catalogue supplied by EHF, D-10) and volunteer training records. A renewal is a new row. */
final class TrainingService
{
    public static function types(bool $activeOnly = false): array
    {
        return Database::get()->all('SELECT * FROM training_types' . ($activeOnly ? ' WHERE is_active = 1' : '') . ' ORDER BY name');
    }

    public static function saveType(?int $id, array $in): int
    {
        $name = trim((string) ($in['name'] ?? ''));
        $months = ($in['validity_months'] ?? '') !== '' ? (int) $in['validity_months'] : null;
        if ($name === '' || mb_strlen($name) > 120) { throw new ValidationException(['Enter a name (up to 120 characters).']); }
        if ($months !== null && ($months < 1 || $months > 240)) { throw new ValidationException(['Validity must be between 1 and 240 months, or blank for no expiry.']); }
        return Database::get()->transaction(function (Database $db) use ($id, $name, $months, $in) {
            $data = ['name' => $name, 'validity_months' => $months, 'is_mandatory' => ($in['is_mandatory'] ?? '') === '1' ? 1 : 0, 'is_active' => ($in['is_active'] ?? '1') === '1' ? 1 : 0];
            try {
                if ($id === null) {
                    $id = $db->insert('training_types', $data + ['created_at' => now_utc()]);
                    AuditLogger::record('training_type.create', 'success', 'training_type', $id);
                } else {
                    $db->update('training_types', $data, 'id = :id', ['id' => $id]);
                    AuditLogger::record('training_type.update', 'success', 'training_type', $id);
                }
            } catch (\PDOException $e) {
                if (($e->errorInfo[1] ?? 0) === 1062) { throw new ValidationException(['A training type with this name already exists.']); }
                throw $e;
            }
            return $id;
        });
    }

    public static function record(int $vid, array $in): int
    {
        Gate::authorize('training.manage');
        $typeId = (int) ($in['training_type_id'] ?? 0);
        $status = (string) ($in['status'] ?? '');
        $done = ($in['completed_on'] ?? '') ?: null;
        if (!in_array($status, ['not_started', 'in_progress', 'completed', 'waived'], true)) { throw new ValidationException(['Choose a status.']); }
        if ($status === 'completed' && ($done === null || !ProjectService::isDate($done) || $done > today_local())) { throw new ValidationException(['Enter the completion date (not in the future).']); }
        return Database::get()->transaction(function (Database $db) use ($vid, $typeId, $status, $done, $in) {
            $type = $db->one('SELECT * FROM training_types WHERE id = ? AND is_active = 1', [$typeId]);
            if (!$type) { throw new ValidationException(['Choose a training type.']); }
            if (!$db->value('SELECT 1 FROM volunteers WHERE id = ? AND deleted_at IS NULL', [$vid])) { Gate::notFound('volunteer'); }
            $expires = ($status === 'completed' && $type['validity_months']) ? (new \DateTimeImmutable($done))->modify('+' . (int) $type['validity_months'] . ' months')->format('Y-m-d') : null;
            $id = $db->insert('volunteer_training', ['volunteer_id' => $vid, 'training_type_id' => $typeId, 'status' => $status,
                'completed_on' => $status === 'completed' ? $done : null, 'expires_on' => $expires, 'recorded_by' => Auth::id(),
                'notes' => trim((string) ($in['notes'] ?? '')) ?: null, 'created_at' => now_utc(), 'updated_at' => now_utc()]);
            AuditLogger::record('training.record', 'success', 'volunteer_training', $id, ['volunteer' => (string) $vid, 'status' => $status]);
            return $id;
        });
    }

    /** Compliance: latest record per volunteer per mandatory type; expired/expiring calculated, never stored. */
    public static function compliance(): array
    {
        return Database::get()->all("SELECT v.id AS volunteer_id, v.full_name, tt.name AS type_name, vt.status, vt.completed_on, vt.expires_on,
            CASE WHEN vt.id IS NULL THEN 'missing' WHEN vt.status = 'waived' THEN 'waived' WHEN vt.status <> 'completed' THEN 'incomplete'
                 WHEN vt.expires_on IS NOT NULL AND vt.expires_on < :t THEN 'expired'
                 WHEN vt.expires_on IS NOT NULL AND vt.expires_on <= DATE_ADD(:t2, INTERVAL 30 DAY) THEN 'expiring' ELSE 'ok' END AS state
            FROM volunteers v CROSS JOIN training_types tt
            LEFT JOIN volunteer_training vt ON vt.id = (SELECT x.id FROM volunteer_training x WHERE x.volunteer_id = v.id AND x.training_type_id = tt.id
                ORDER BY x.completed_on IS NULL, x.completed_on DESC, x.id DESC LIMIT 1)
            WHERE v.status = 'active' AND v.deleted_at IS NULL AND tt.is_mandatory = 1 AND tt.is_active = 1 ORDER BY v.full_name, tt.name",
            ['t' => today_local(), 't2' => today_local()]);
    }
}
