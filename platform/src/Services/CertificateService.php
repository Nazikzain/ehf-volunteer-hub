<?php
declare(strict_types=1);

namespace App\Services;

use App\Audit\AuditLogger;
use App\Core\Database;
use App\Core\ValidationException;
use App\Security\Auth;
use App\Security\Gate;

/** Certificates: hours captured at issue; status flow with DB CHECKs (E3). */
final class CertificateService
{
    public const FLOW = ['not_eligible' => ['eligible', 'cancelled'], 'eligible' => ['approved', 'cancelled'], 'approved' => ['issued', 'cancelled'],
        'issued' => ['delivered', 'cancelled'], 'delivered' => [], 'cancelled' => []];

    public static function list(): array
    {
        $sql = 'SELECT c.*, v.full_name, (SELECT COALESCE(SUM(minutes),0) FROM time_logs t WHERE t.volunteer_id = c.volunteer_id AND t.status = \'approved\') AS current_minutes
            FROM certificates c JOIN volunteers v ON v.id = c.volunteer_id';
        $p = [];
        if (!Auth::isAdmin()) {
            $sql .= ' WHERE c.volunteer_id = :me AND c.status IN (\'issued\', \'delivered\')';
            $p['me'] = (int) Auth::volunteerId();
        }
        return Database::get()->all($sql . ' ORDER BY c.created_at DESC LIMIT 500', $p);
    }

    public static function create(int $vid, ?int $requiredHours, string $notes): int
    {
        Gate::authorize('certificates.manage');
        if ($requiredHours !== null && ($requiredHours < 0 || $requiredHours > 10000)) { throw new ValidationException(['Enter required hours between 0 and 10000.']); }
        return Database::get()->transaction(function (Database $db) use ($vid, $requiredHours, $notes) {
            if (!$db->value('SELECT 1 FROM volunteers WHERE id = ? AND deleted_at IS NULL AND anonymised_at IS NULL', [$vid])) { Gate::notFound('volunteer'); }
            $mins = (int) $db->value("SELECT COALESCE(SUM(minutes),0) FROM time_logs WHERE volunteer_id = ? AND status = 'approved'", [$vid]);
            $status = $requiredHours === null || $mins >= $requiredHours * 60 ? 'eligible' : 'not_eligible';
            $id = $db->insert('certificates', ['volunteer_id' => $vid, 'status' => $status, 'required_hours' => $requiredHours, 'notes' => trim($notes) ?: null,
                'created_by' => Auth::id(), 'created_at' => now_utc(), 'updated_at' => now_utc()]);
            AuditLogger::record('certificate.create', 'success', 'certificate', $id, ['status' => $status]);
            return $id;
        });
    }

    public static function advance(int $id, string $to): void
    {
        Gate::authorize('certificates.manage');
        Database::get()->transaction(function (Database $db) use ($id, $to) {
            $c = $db->one('SELECT * FROM certificates WHERE id = ? FOR UPDATE', [$id]);
            if (!$c) { Gate::notFound('certificate'); }
            if (!in_array($to, self::FLOW[$c['status']] ?? [], true)) { throw new ValidationException(['That status change is not allowed.']); }
            $mins = (int) $db->value("SELECT COALESCE(SUM(minutes),0) FROM time_logs WHERE volunteer_id = ? AND status = 'approved'", [$c['volunteer_id']]);
            $now = now_utc();
            $set = ['status' => $to, 'updated_at' => $now];
            if ($to === 'eligible' && $c['required_hours'] !== null && $mins < (int) $c['required_hours'] * 60) {
                throw new ValidationException(['The volunteer does not yet have the required approved hours.']);
            }
            if ($to === 'approved') { $set += ['approved_by' => Auth::id(), 'approved_at' => $now, 'reference' => 'EHF-' . gmdate('Y') . '-' . str_pad((string) $id, 5, '0', STR_PAD_LEFT)]; }
            if ($to === 'issued') { $set += ['issued_at' => $now, 'approved_minutes_at_issue' => $mins]; }
            if ($to === 'delivered') { $set += ['delivered_at' => $now]; }
            $db->update('certificates', $set, 'id = :id', ['id' => $id]);
            AuditLogger::record('certificate.status_change', 'success', 'certificate', $id, ['from' => $c['status'], 'to' => $to]);
            if ($to === 'issued') {
                $uid = $db->value('SELECT user_id FROM volunteers WHERE id = ?', [$c['volunteer_id']]);
                if ($uid) { Notifier::notify((int) $uid, 'certificate', 'Your volunteering certificate has been issued', null, '/certificates/' . $id); }
            }
        });
    }

    /** Printable certificate (HTML; use the browser's "Save as PDF"). Volunteer: own issued/delivered only. */
    public static function forPrint(int $id): array
    {
        $c = Database::get()->one('SELECT c.*, v.full_name FROM certificates c JOIN volunteers v ON v.id = c.volunteer_id WHERE c.id = ?', [$id]);
        if (!$c || !in_array($c['status'], ['issued', 'delivered'], true)) { Gate::notFound('certificate'); }
        if (!Auth::isAdmin() && (int) $c['volunteer_id'] !== Auth::volunteerId()) { Gate::notFound('certificate'); }
        return $c;
    }
}
