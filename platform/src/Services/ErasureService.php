<?php
declare(strict_types=1);

namespace App\Services;

use App\Audit\AuditLogger;
use App\Core\Database;
use App\Core\ValidationException;
use App\Security\Auth;
use App\Security\Gate;

/**
 * Volunteer erasure, workflow W4 (Design Rev3 §7). Two-person rule (D-06 conservative default):
 * an Organisation Admin prepares, the Primary Admin approves. The pending request is stored in
 * settings ('erasure.request.<id>') to keep the 34-table model (TD-12).
 * Not a legal determination: scope follows the conservative proposal pending the Data Protection Lead (D-05).
 */
final class ErasureService
{
    private static function key(int $vid): string
    {
        return 'erasure.request.' . $vid;
    }

    public static function pending(int $vid): ?array
    {
        $v = Settings::get(self::key($vid));
        return $v ? json_decode($v, true) : null;
    }

    /** Linked applications plus unlinked ones with the same email (each must be confirmed by the approver). */
    public static function candidates(int $vid): array
    {
        $db = Database::get();
        $v = $db->one('SELECT email FROM volunteers WHERE id = ?', [$vid]);
        return $db->all('SELECT id, reference, status, submitted_at, (volunteer_id = :v) AS linked FROM volunteer_applications
            WHERE volunteer_id = :v OR (volunteer_id IS NULL AND email = :e) ORDER BY submitted_at', ['v' => $vid, 'e' => $v['email'] ?? '']);
    }

    public static function prepare(int $vid, string $requestRef): void
    {
        Gate::authorize('erasure.prepare');
        $requestRef = trim($requestRef);
        if ($requestRef === '' || mb_strlen($requestRef) > 100) {
            throw new ValidationException(['Enter the reference of the erasure request (e.g. the date and channel it was received through).']);
        }
        Database::get()->transaction(function (Database $db) use ($vid, $requestRef) {
            $v = $db->one('SELECT id, anonymised_at, user_id FROM volunteers WHERE id = ? FOR UPDATE', [$vid]);
            if (!$v) { Gate::notFound('volunteer'); }
            if ($v['anonymised_at'] !== null) { throw new ValidationException(['This volunteer has already been erased.']); }
            if ($v['user_id'] && $db->value('SELECT 1 FROM system_owner WHERE user_id = ?', [$v['user_id']])) {
                throw new ValidationException(['The Primary Admin account cannot be erased. Transfer ownership first.']);
            }
            Settings::set(self::key($vid), json_encode(['prepared_by' => Auth::id(), 'prepared_at' => now_utc(), 'request_ref' => $requestRef]), Auth::id());
            AuditLogger::record('volunteer.erasure_prepared', 'success', 'volunteer', $vid);
        });
        Notifier::notifyAdmins('erasure', 'A volunteer erasure request awaits Primary Admin approval', null, '/volunteers/' . $vid);
    }

    public static function cancel(int $vid): void
    {
        Gate::authorize('erasure.prepare');
        Database::get()->transaction(function (Database $db) use ($vid) {
            $db->run('DELETE FROM settings WHERE `key` = ?', [self::key($vid)]);
            AuditLogger::record('volunteer.erasure_cancelled', 'success', 'volunteer', $vid);
        });
    }

    /** Execute W4 in one transaction. $confirmedUnlinked = ids of unlinked same-email applications the approver confirmed. */
    public static function approveAndExecute(int $vid, array $confirmedUnlinked, bool $confirm1, bool $confirm2): array
    {
        Gate::authorize('erasure.approve');
        if (!$confirm1 || !$confirm2) { throw new ValidationException(['Both confirmations are required. Erasure cannot be undone.']); }
        $req = self::pending($vid);
        if ($req === null) { throw new ValidationException(['There is no prepared erasure request for this volunteer.']); }
        if ((int) $req['prepared_by'] === Auth::id()) {
            throw new ValidationException(['The person who prepared the request cannot also approve it (two-person rule).']);
        }
        $confirmedUnlinked = array_map('intval', $confirmedUnlinked);
        return Database::get()->transaction(function (Database $db) use ($vid, $confirmedUnlinked) {
            $v = $db->one('SELECT * FROM volunteers WHERE id = ? FOR UPDATE', [$vid]);
            if (!$v || $v['anonymised_at'] !== null) { throw new ValidationException(['This volunteer cannot be erased.']); }
            $counts = [];
            $apps = array_map('intval', array_column($db->all('SELECT id FROM volunteer_applications WHERE volunteer_id = ?', [$vid]), 'id'));
            foreach ($confirmedUnlinked as $aid) {
                if ($db->value('SELECT 1 FROM volunteer_applications WHERE id = ? AND volunteer_id IS NULL AND email = ?', [$aid, $v['email']])) {
                    $apps[] = $aid;
                }
            }
            $counts['applications_deleted'] = 0;
            foreach (array_unique($apps) as $aid) {
                $counts['applications_deleted'] += $db->run('DELETE FROM volunteer_applications WHERE id = ?', [$aid])->rowCount();
            }
            $counts['notes_deleted'] = $db->run('DELETE FROM volunteer_notes WHERE volunteer_id = ?', [$vid])->rowCount();
            $counts['skills_deleted'] = $db->run('DELETE FROM volunteer_skills WHERE volunteer_id = ?', [$vid])->rowCount();
            $counts['availability_deleted'] = $db->run('DELETE FROM volunteer_availability WHERE volunteer_id = ?', [$vid])->rowCount();
            $counts['time_logs_redacted'] = $db->run("UPDATE time_logs SET description = '[erased]', reviewer_comment = NULL,
                void_reason = CASE WHEN void_reason IS NULL THEN NULL ELSE '[erased]' END WHERE volunteer_id = ?", [$vid])->rowCount();
            $counts['reviews_redacted'] = $db->run('UPDATE performance_reviews SET achievements = NULL, improvement_areas = NULL, notes = NULL WHERE volunteer_id = ?', [$vid])->rowCount();
            $db->run('UPDATE volunteer_training SET notes = NULL WHERE volunteer_id = ?', [$vid]);
            $db->run('UPDATE certificates SET notes = NULL WHERE volunteer_id = ?', [$vid]);
            $db->run('UPDATE volunteer_status_history SET reason = NULL WHERE volunteer_id = ?', [$vid]);
            $db->run("DELETE FROM email_outbox WHERE to_email = ? AND status IN ('queued','sending')", [$v['email']]);
            $db->run("UPDATE volunteers SET full_name = ?, email = ?, phone = NULL, postcode = NULL, preferred_language = NULL,
                anonymised_at = ?, updated_at = ? WHERE id = ?", ["Erased volunteer #{$vid}", "erased-{$vid}@invalid", now_utc(), now_utc(), $vid]);
            if ($v['user_id']) {
                $u = $db->one('SELECT email, anonymised_at FROM users WHERE id = ?', [$v['user_id']]);
                if ($u && $u['anonymised_at'] === null) {
                    UserService::anonymiseRow($db, (int) $v['user_id'], $u['email']);
                    AuditLogger::record('user.anonymised', 'success', 'user', (int) $v['user_id'], ['via' => 'volunteer_erasure']);
                }
            }
            $db->run('DELETE FROM settings WHERE `key` = ?', [self::key($vid)]);
            AuditLogger::record('volunteer.erased', 'success', 'volunteer', $vid, $counts);
            return $counts;
        });
    }
}
