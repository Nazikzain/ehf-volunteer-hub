<?php
declare(strict_types=1);

namespace App\Audit;

use App\Core\Database;
use App\Core\ErrorHandler;
use App\Security\Auth;

/**
 * The only writer of audit_logs (Design Rev3 §6.2). Entries are HMAC-chained with a gap-free
 * sequence issued under a row lock on audit_chain, always acquired last in the transaction.
 */
final class AuditLogger
{
    public static string $requestId = 'cli0000000000000';
    public static ?string $ip = null;

    /** Fixed action list. Unknown codes are rejected. */
    public const ACTIONS = [
        'system.installed', 'system.migrated',
        'auth.login', 'auth.login_failed', 'auth.logout', 'auth.lockout', 'auth.password_reset_requested',
        'auth.password_reset_completed', 'user.activated', 'access.denied', 'csrf.rejected',
        'user.invited', 'user.invitation_deleted', 'user.deactivated', 'user.reactivated', 'user.anonymised',
        'user.role_grant', 'user.role_revoke', 'user.activation_reissued', 'owner.transfer',
        'application.submitted', 'application.status_change', 'application.approve', 'application.reject',
        'volunteer.create', 'volunteer.update', 'volunteer.status_change', 'volunteer.skills_update',
        'volunteer.availability_update', 'volunteer.erasure_prepared', 'volunteer.erasure_cancelled', 'volunteer.erased',
        'note.create', 'note.delete',
        'project.create', 'project.update', 'project.status_change',
        'supervisor.assign', 'supervisor.end', 'supervisor.reappoint',
        'period.create', 'period.end', 'period.update_dates',
        'activity.create', 'activity.update', 'activity.status_change',
        'roster.add', 'roster.remove', 'attendance.record', 'attendance.correct',
        'task.create', 'task.update', 'task.status_change', 'task.assign',
        'timelog.submit', 'timelog.edit', 'timelog.approve', 'timelog.reject', 'timelog.revision_request', 'timelog.void',
        'review.create', 'review.update', 'review.delete', 'review.share',
        'training_type.create', 'training_type.update', 'training.record', 'training.update',
        'certificate.create', 'certificate.status_change',
        'announcement.create', 'announcement.update', 'announcement.delete',
        'settings.update', 'export.personal_data', 'report.export',
        'audit.write_failed', 'audit.verify', 'audit.anchor', 'audit.restore', 'audit.key_rotated', 'audit.key_compromised',
        'audit.fallback_file_reconciled', 'audit.fallback_line_rejected', 'audit.fallback_gap', 'audit.archive_checkpoint',
        'integrity.report', 'email.queued',
    ];

    public static function record(string $action, string $outcome = 'success', ?string $targetType = null,
                                  int|string|null $targetId = null, array $details = [], ?array $actor = null): void
    {
        $event = self::buildEvent($action, $outcome, $targetType, $targetId, $details, $actor);
        $db = Database::get();
        if ($db->inTransaction()) {
            // Success path: same transaction as the business change ("no audit, no change").
            try {
                self::append($db, $event, 'direct', null);
            } catch (\Throwable $e) {
                self::fallbackFailure($action, $e);
                throw $e;
            }
            return;
        }
        try {
            $db->transaction(fn(Database $d) => self::append($d, $event, 'direct', null));
        } catch (\Throwable $e) {
            ErrorHandler::log($e, self::$requestId);
            FallbackLog::write($event); // denials/failures are never lost with a rollback
        }
    }

    /** Write audit.write_failed to the fallback file (no personal data). */
    private static function fallbackFailure(string $action, \Throwable $e): void
    {
        ErrorHandler::log($e, self::$requestId);
        try {
            FallbackLog::write(self::buildEvent('audit.write_failed', 'failed', null, null,
                ['failed_action' => $action, 'error_class' => $e::class], null));
        } catch (\Throwable) {
            // FallbackLog::write already reports to the PHP error log as last resort.
        }
    }

    public static function buildEvent(string $action, string $outcome, ?string $targetType, int|string|null $targetId,
                                      array $details, ?array $actor): array
    {
        if (!in_array($action, self::ACTIONS, true)) {
            throw new \InvalidArgumentException("Unknown audit action: {$action}");
        }
        if (!in_array($outcome, ['success', 'denied', 'failed'], true)) {
            throw new \InvalidArgumentException('Bad outcome');
        }
        $actor ??= Auth::actorSnapshot();
        $norm = Canonical::normaliseDetails($details);
        return [
            'occurred_at' => now_utc(true),
            'actor_user_id' => $actor['user_id'] ?? null,
            'actor_email' => $actor['email'] ?? null,
            'actor_role' => $actor['role'] ?? null,
            'action' => $action,
            'outcome' => $outcome,
            'target_type' => $targetType,
            'target_id' => $targetId === null ? null : (int) $targetId,
            'ip' => self::$ip !== null ? (@inet_pton(self::$ip) ?: null) : null,
            'request_id' => self::$requestId,
            'details' => Canonical::detailsText($norm),
        ];
    }

    /** Append one event to the chain. Must run inside a transaction. */
    public static function append(Database $db, array $event, string $source, ?string $fallbackRef, ?int $keyId = null): int
    {
        $keyId ??= Keys::activeId();
        $head = $db->one("SELECT last_seq, last_hash FROM audit_chain WHERE singleton = 'head' FOR UPDATE");
        if ($head === null) {
            throw new \RuntimeException('Audit chain head missing; run the installer');
        }
        $row = $event + [
            'seq' => (int) $head['last_seq'] + 1,
            'canon_version' => Canonical::VERSION,
            'recorded_at' => now_utc(true),
            'source' => $source,
            'fallback_ref' => $fallbackRef,
            'key_id' => $keyId,
            'prev_hash' => $head['last_hash'],
        ];
        $row['row_hash'] = Keys::mac($keyId, Canonical::encodeRow($row));
        $db->insert('audit_logs', $row);
        $db->run("UPDATE audit_chain SET last_seq = :s, last_hash = :h, updated_at = :u WHERE singleton = 'head'",
            ['s' => $row['seq'], 'h' => $row['row_hash'], 'u' => now_utc(true)]);
        return $row['seq'];
    }
}
