<?php
declare(strict_types=1);

namespace App\Services;

use App\Audit\AuditLogger;
use App\Core\Database;
use App\Core\ValidationException;
use App\Security\Auth;
use App\Security\Gate;

final class VolunteerService
{
    public const STATUSES = ['approved', 'active', 'inactive', 'completed', 'withdrawn'];
    public const PERIODS = ['morning', 'afternoon', 'evening', 'any'];

    /** Directory, scoped: admins all; supervisors volunteers with a period on their active projects. */
    public static function list(array $f, int $page = 1, int $per = 25): array
    {
        $where = ['v.deleted_at IS NULL'];
        $p = [];
        if (!Auth::isAdmin()) {
            $ids = Auth::supervisedProjectIds();
            if (!$ids) {
                return ['rows' => [], 'total' => 0, 'pages' => 1, 'page' => 1];
            }
            $where[] = 'EXISTS (SELECT 1 FROM project_assignments pa WHERE pa.volunteer_id = v.id AND pa.project_id IN (' . implode(',', array_map('intval', $ids)) . '))';
        }
        if (!empty($f['status'])) { $where[] = 'v.status = :st'; $p['st'] = $f['status']; }
        if (!empty($f['q'])) { $where[] = '(v.full_name LIKE :q OR v.email LIKE :q)'; $p['q'] = '%' . $f['q'] . '%'; }
        if (!empty($f['skill'])) { $where[] = 'EXISTS (SELECT 1 FROM volunteer_skills vs WHERE vs.volunteer_id = v.id AND vs.skill_id = :sk)'; $p['sk'] = (int) $f['skill']; }
        if (!empty($f['project'])) { $where[] = 'EXISTS (SELECT 1 FROM project_assignments pa2 WHERE pa2.volunteer_id = v.id AND pa2.project_id = :pr)'; $p['pr'] = (int) $f['project']; }
        $w = implode(' AND ', $where);
        $db = Database::get();
        $total = (int) $db->value("SELECT COUNT(*) FROM volunteers v WHERE {$w}", $p);
        $off = max(0, ($page - 1) * $per);
        $rows = $db->all("SELECT v.id, v.full_name, v.email, v.phone, v.status, v.start_date,
            (SELECT GROUP_CONCAT(s.name ORDER BY s.name SEPARATOR ', ') FROM volunteer_skills vs JOIN skills s ON s.id = vs.skill_id WHERE vs.volunteer_id = v.id) AS skills
            FROM volunteers v WHERE {$w} ORDER BY v.full_name LIMIT {$per} OFFSET {$off}", $p);
        return ['rows' => $rows, 'total' => $total, 'pages' => max(1, (int) ceil($total / $per)), 'page' => $page];
    }

    public static function get(int $id): array
    {
        Scope::assertViewVolunteer($id);
        $db = Database::get();
        $v = $db->one('SELECT * FROM volunteers WHERE id = ? AND deleted_at IS NULL', [$id]);
        if (!$v) {
            Gate::notFound('volunteer');
        }
        $v['skills'] = $db->all('SELECT s.id, s.name, vs.proficiency, vs.source FROM volunteer_skills vs JOIN skills s ON s.id = vs.skill_id WHERE vs.volunteer_id = ? ORDER BY s.name', [$id]);
        $v['availability'] = $db->all('SELECT weekday, period FROM volunteer_availability WHERE volunteer_id = ? ORDER BY weekday', [$id]);
        $v['history'] = $db->all('SELECT h.*, u.email AS by_email FROM volunteer_status_history h LEFT JOIN users u ON u.id = h.changed_by WHERE h.volunteer_id = ? ORDER BY h.changed_at DESC, h.id DESC', [$id]);
        $p = [];
        $f = Scope::projectFilter('pa.project_id', $p);
        $v['periods'] = $db->all('SELECT pa.*, pr.name AS project_name FROM project_assignments pa JOIN projects pr ON pr.id = pa.project_id
            WHERE pa.volunteer_id = :vid' . ($f ? " AND {$f}" : '') . ' ORDER BY pa.start_date DESC', ['vid' => $id] + $p);
        $v['approved_minutes'] = (int) $db->value("SELECT COALESCE(SUM(minutes), 0) FROM time_logs WHERE volunteer_id = ? AND status = 'approved'", [$id]);
        $v['attendance'] = $db->one("SELECT SUM(a.status IN ('present','late')) AS attended, COUNT(*) AS recorded FROM attendance a WHERE a.volunteer_id = ?", [$id]);
        $v['training'] = $db->all('SELECT vt.*, tt.name AS type_name FROM volunteer_training vt JOIN training_types tt ON tt.id = vt.training_type_id WHERE vt.volunteer_id = ? ORDER BY vt.created_at DESC', [$id]);
        $v['certificates'] = $db->all('SELECT * FROM certificates WHERE volunteer_id = ? ORDER BY created_at DESC', [$id]);
        $v['applications'] = Auth::isAdmin() ? $db->all('SELECT id, reference, status, submitted_at FROM volunteer_applications WHERE volunteer_id = ? ORDER BY submitted_at DESC', [$id]) : [];
        $v['notes'] = NoteService::forVolunteer($id);
        $v['erasure_request'] = Auth::isAdmin() ? ErasureService::pending($id) : null;
        return $v;
    }

    public static function update(int $id, array $in): void
    {
        $name = trim((string) ($in['full_name'] ?? ''));
        $email = strtolower(trim((string) ($in['email'] ?? '')));
        $phone = trim((string) ($in['phone'] ?? '')) ?: null;
        $postcode = strtoupper(trim((string) ($in['postcode'] ?? ''))) ?: null;
        $lang = trim((string) ($in['preferred_language'] ?? '')) ?: null;
        $e = [];
        if ($name === '' || mb_strlen($name) > 150) { $e[] = 'Enter the full name.'; }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { $e[] = 'Enter a valid email address.'; }
        if ($phone !== null && !preg_match('/^[0-9 +()\-]{6,40}$/', $phone)) { $e[] = 'Enter a valid phone number.'; }
        if ($postcode !== null && !preg_match('/^[A-Z0-9 ]{2,12}$/', $postcode)) { $e[] = 'Enter a valid postcode.'; }
        if ($lang !== null && mb_strlen($lang) > 35) { $e[] = 'Preferred language is too long.'; }
        if ($e) { throw new ValidationException($e); }
        Database::get()->transaction(function (Database $db) use ($id, $name, $email, $phone, $postcode, $lang) {
            $old = $db->one('SELECT * FROM volunteers WHERE id = ? AND deleted_at IS NULL FOR UPDATE', [$id]);
            if (!$old) { Gate::notFound('volunteer'); }
            $new = ['full_name' => $name, 'email' => $email, 'phone' => $phone, 'postcode' => $postcode, 'preferred_language' => $lang];
            $changed = array_keys(array_filter($new, fn($v, $k) => $old[$k] !== $v, ARRAY_FILTER_USE_BOTH));
            try {
                $db->update('volunteers', $new + ['updated_at' => now_utc()], 'id = :id', ['id' => $id]);
            } catch (\PDOException $ex) {
                if (($ex->errorInfo[1] ?? 0) === 1062) { throw new ValidationException(['Another volunteer already uses this email address.']); }
                throw $ex;
            }
            AuditLogger::record('volunteer.update', 'success', 'volunteer', $id, ['fields' => $changed]);
        });
    }

    /** Volunteer self-service: phone, postcode, preferred language only (Design §4.5). */
    public static function selfUpdate(array $in): void
    {
        $id = Auth::volunteerId();
        if ($id === null) { Gate::notFound('volunteer'); }
        $phone = trim((string) ($in['phone'] ?? '')) ?: null;
        $postcode = strtoupper(trim((string) ($in['postcode'] ?? ''))) ?: null;
        $lang = trim((string) ($in['preferred_language'] ?? '')) ?: null;
        $e = [];
        if ($phone !== null && !preg_match('/^[0-9 +()\-]{6,40}$/', $phone)) { $e[] = 'Enter a valid phone number.'; }
        if ($postcode !== null && !preg_match('/^[A-Z0-9 ]{2,12}$/', $postcode)) { $e[] = 'Enter a valid postcode.'; }
        if ($lang !== null && mb_strlen($lang) > 35) { $e[] = 'Preferred language is too long.'; }
        if ($e) { throw new ValidationException($e); }
        Database::get()->transaction(function (Database $db) use ($id, $phone, $postcode, $lang) {
            $db->update('volunteers', ['phone' => $phone, 'postcode' => $postcode, 'preferred_language' => $lang, 'updated_at' => now_utc()], 'id = :id', ['id' => $id]);
            AuditLogger::record('volunteer.update', 'success', 'volunteer', $id, ['fields' => ['phone', 'postcode', 'preferred_language'], 'self' => 'true']);
        });
    }

    public static function setStatus(int $id, string $to, string $reason): void
    {
        if (!in_array($to, self::STATUSES, true)) { throw new ValidationException(['Unknown status.']); }
        Database::get()->transaction(function (Database $db) use ($id, $to, $reason) {
            $v = $db->one('SELECT status FROM volunteers WHERE id = ? AND deleted_at IS NULL FOR UPDATE', [$id]);
            if (!$v) { Gate::notFound('volunteer'); }
            if ($v['status'] === $to) { throw new ValidationException(['The volunteer already has this status.']); }
            $end = in_array($to, ['completed', 'withdrawn'], true) ? today_local() : null;
            $db->run('UPDATE volunteers SET status = ?, end_date = COALESCE(?, end_date), updated_at = ? WHERE id = ?', [$to, $end, now_utc(), $id]);
            $db->insert('volunteer_status_history', ['volunteer_id' => $id, 'from_status' => $v['status'], 'to_status' => $to,
                'changed_by' => Auth::id(), 'changed_at' => now_utc(), 'reason' => mb_substr($reason, 0, 255) ?: null]);
            AuditLogger::record('volunteer.status_change', 'success', 'volunteer', $id, ['from' => $v['status'], 'to' => $to]);
        });
    }

    public static function setSkills(int $id, array $skillIds): void
    {
        $skillIds = array_values(array_unique(array_map('intval', $skillIds)));
        Database::get()->transaction(function (Database $db) use ($id, $skillIds) {
            if (!$db->value('SELECT 1 FROM volunteers WHERE id = ? AND deleted_at IS NULL', [$id])) { Gate::notFound('volunteer'); }
            $db->run('DELETE FROM volunteer_skills WHERE volunteer_id = ?' . ($skillIds ? ' AND skill_id NOT IN (' . implode(',', $skillIds) . ')' : ''), [$id]);
            foreach ($skillIds as $s) {
                $db->run("INSERT IGNORE INTO volunteer_skills (volunteer_id, skill_id, proficiency, source, verified_by, verified_at, created_at)
                    SELECT ?, id, NULL, 'admin', ?, ?, ? FROM skills WHERE id = ? AND is_active = 1", [$id, Auth::id(), now_utc(), now_utc(), $s]);
            }
            AuditLogger::record('volunteer.skills_update', 'success', 'volunteer', $id, ['skills' => array_map('strval', $skillIds)]);
        });
    }

    /** Availability: by an admin, or by the volunteer for themselves. $slots = ["1:morning", ...] */
    public static function setAvailability(int $id, array $slots): void
    {
        if (!Auth::isAdmin() && Auth::volunteerId() !== $id) { Gate::notFound('volunteer'); }
        $clean = [];
        foreach ($slots as $s) {
            [$d, $per] = array_pad(explode(':', (string) $s, 2), 2, '');
            if (ctype_digit($d) && (int) $d >= 1 && (int) $d <= 7 && in_array($per, self::PERIODS, true)) {
                $clean[(int) $d . ':' . $per] = [(int) $d, $per];
            }
        }
        Database::get()->transaction(function (Database $db) use ($id, $clean) {
            $db->run('DELETE FROM volunteer_availability WHERE volunteer_id = ?', [$id]);
            foreach ($clean as [$d, $per]) {
                $db->insert('volunteer_availability', ['volunteer_id' => $id, 'weekday' => $d, 'period' => $per, 'updated_at' => now_utc()]);
            }
            AuditLogger::record('volunteer.availability_update', 'success', 'volunteer', $id, ['slots' => (string) count($clean)]);
        });
    }
}
