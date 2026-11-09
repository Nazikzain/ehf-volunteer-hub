<?php
declare(strict_types=1);

namespace App\Services;

use App\Audit\AuditLogger;
use App\Core\Database;
use App\Core\ValidationException;
use App\Security\Auth;
use App\Security\RateLimiter;
use App\Security\Tokens;

/** Recruitment: public applications and their review (Design Rev3 §5 B1-B4, §4.5). */
final class ApplicationService
{
    public const EXPERTISE = ['Community Engagement', 'Health and Wellbeing', 'Administration', 'IT & Digital Skills',
        'Data Analysis', 'Marketing & Communications', 'Events & Activities', 'Translation & Languages', 'Project Management', 'Other'];
    public const REFERRAL = ['Website', 'Social Media', 'Friend or Family', 'Community Organisation', 'Event', 'Other'];
    public const DAYS = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];

    /** Validate the public form exactly as the existing registration form (Phase A §1.2) plus the fixed gaps. */
    public static function validate(array $in): array
    {
        $e = [];
        $name = trim((string) ($in['full_name'] ?? ''));
        $email = strtolower(trim((string) ($in['email'] ?? '')));
        $phone = trim((string) ($in['phone'] ?? ''));
        $postcode = strtoupper(trim((string) ($in['postcode'] ?? '')));
        $expertise = (string) ($in['expertise'] ?? '');
        $avail = (string) ($in['availability_type'] ?? '');
        $days = array_values(array_unique(array_map('intval', (array) ($in['available_days'] ?? []))));
        $referral = (string) ($in['referral_source'] ?? '');
        $motivation = trim((string) ($in['motivation'] ?? ''));
        if ($name === '' || mb_strlen($name) > 150) { $e[] = 'Please enter your full name (up to 150 characters).'; }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 254) { $e[] = 'Please enter a valid email address.'; }
        if ($phone !== '' && (mb_strlen($phone) > 40 || !preg_match('/^[0-9 +()\-]{6,40}$/', $phone))) { $e[] = 'Please enter a valid phone number.'; }
        if ($postcode !== '' && !preg_match('/^[A-Z0-9 ]{2,12}$/', $postcode)) { $e[] = 'Please enter a valid postcode.'; }
        if (!in_array($expertise, self::EXPERTISE, true)) { $e[] = 'Please choose a skill or area of expertise.'; }
        if (!in_array($avail, ['specific_days', 'flexible'], true)) { $e[] = 'Please choose your availability.'; }
        if ($avail === 'specific_days' && (count($days) === 0 || array_diff($days, array_keys(self::DAYS)))) { $e[] = 'Please choose at least one day you are available.'; }
        if ($referral !== '' && !in_array($referral, self::REFERRAL, true)) { $e[] = 'Please choose how you heard about us.'; }
        if (mb_strlen($motivation) > 2000) { $e[] = 'Please keep your answer to 2000 characters or fewer.'; }
        if (($in['privacy_consent'] ?? '') !== '1') { $e[] = 'Please agree to the privacy notice before submitting.'; }
        if ($e) {
            throw new ValidationException($e);
        }
        return ['full_name' => $name, 'email' => $email, 'phone' => $phone ?: null, 'postcode' => $postcode ?: null,
            'expertise' => $expertise, 'availability_type' => $avail, 'days' => $avail === 'specific_days' ? $days : [],
            'referral_source' => $referral ?: null, 'motivation' => $motivation ?: null];
    }

    /**
     * Submit a public application. Returns the reference, or null when silently dropped
     * (duplicate open application or honeypot). Callers show the same message either way so the
     * form cannot be used to discover whether someone has applied (ST-40).
     */
    public static function submit(array $in, string $ip): ?string
    {
        if (($in['website'] ?? '') !== '') {
            return null; // honeypot (kept from the existing form)
        }
        if (RateLimiter::tooMany('registration', 'ip:' . $ip)) {
            throw new ValidationException(['We have received several applications from this connection. Please try again later.']);
        }
        $d = self::validate($in);
        RateLimiter::hit('registration', 'ip:' . $ip);
        $db = Database::get();
        $ref = self::reference();
        try {
            return $db->transaction(function (Database $db) use ($d, $ref) {
                $now = now_utc();
                $id = $db->insert('volunteer_applications', ['reference' => $ref, 'full_name' => $d['full_name'], 'email' => $d['email'],
                    'phone' => $d['phone'], 'postcode' => $d['postcode'], 'availability_type' => $d['availability_type'],
                    'motivation' => $d['motivation'], 'referral_source' => $d['referral_source'], 'privacy_consent' => 1,
                    'consent_version' => Settings::get('consent.version', 'unversioned'), 'consent_at' => $now, 'status' => 'pending',
                    'submitted_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
                foreach ($d['days'] as $day) {
                    $db->insert('application_available_days', ['application_id' => $id, 'weekday' => $day]);
                }
                $skill = $db->value('SELECT id FROM skills WHERE name = ?', [$d['expertise']]);
                if ($skill) {
                    $db->insert('application_skills', ['application_id' => $id, 'skill_id' => (int) $skill]);
                }
                $db->insert('application_status_history', ['application_id' => $id, 'from_status' => null, 'to_status' => 'pending',
                    'changed_by' => null, 'changed_at' => $now, 'note' => null]);
                AuditLogger::record('application.submitted', 'success', 'volunteer_application', $id, [], ['user_id' => null, 'email' => null, 'role' => null]);
                Notifier::notifyAdmins('application', 'New volunteer application', 'Reference ' . $ref, '/admin/applications/' . $id);
                return $ref;
            });
        } catch (\PDOException $e) {
            if (($e->errorInfo[1] ?? 0) === 1062 && str_contains((string) $e->getMessage(), 'uq_volunteer_applications_2')) {
                AuditLogger::record('application.submitted', 'failed', null, null, ['reason' => 'duplicate_open_application'],
                    ['user_id' => null, 'email' => null, 'role' => null]);
                return null;
            }
            throw $e;
        }
    }

    private static function reference(): string
    {
        $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
        $s = '';
        for ($i = 0; $i < 10; $i++) {
            $s .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        return $s;
    }

    public static function list(array $f, int $page = 1, int $per = 25): array
    {
        $where = ['1=1'];
        $p = [];
        if (!empty($f['status'])) { $where[] = 'a.status = :st'; $p['st'] = $f['status']; }
        if (!empty($f['q'])) { $where[] = '(a.full_name LIKE :q OR a.email LIKE :q OR a.reference = :qr)'; $p['q'] = '%' . $f['q'] . '%'; $p['qr'] = $f['q']; }
        $w = implode(' AND ', $where);
        $db = Database::get();
        $total = (int) $db->value("SELECT COUNT(*) FROM volunteer_applications a WHERE {$w}", $p);
        $off = max(0, ($page - 1) * $per);
        $rows = $db->all("SELECT a.id, a.reference, a.full_name, a.email, a.status, a.submitted_at, a.availability_type,
            (SELECT GROUP_CONCAT(s.name) FROM application_skills x JOIN skills s ON s.id = x.skill_id WHERE x.application_id = a.id) AS skills
            FROM volunteer_applications a WHERE {$w} ORDER BY a.submitted_at DESC LIMIT {$per} OFFSET {$off}", $p);
        return ['rows' => $rows, 'total' => $total, 'pages' => max(1, (int) ceil($total / $per)), 'page' => $page];
    }

    public static function get(int $id): array
    {
        $db = Database::get();
        $a = $db->one('SELECT * FROM volunteer_applications WHERE id = ?', [$id]);
        if (!$a) {
            \App\Security\Gate::notFound('application');
        }
        $a['days'] = array_map('intval', array_column($db->all('SELECT weekday FROM application_available_days WHERE application_id = ? ORDER BY weekday', [$id]), 'weekday'));
        $a['skills'] = array_column($db->all('SELECT s.name FROM application_skills x JOIN skills s ON s.id = x.skill_id WHERE x.application_id = ?', [$id]), 'name');
        $a['history'] = $db->all('SELECT h.*, u.email AS changed_by_email FROM application_status_history h LEFT JOIN users u ON u.id = h.changed_by
            WHERE h.application_id = ? ORDER BY h.changed_at, h.id', [$id]);
        $a['existing_volunteer'] = $db->one('SELECT id, full_name, status FROM volunteers WHERE active_email = ?', [$a['email']]);
        return $a;
    }

    private static function transition(Database $db, array $a, string $to, ?string $note): void
    {
        $allowed = ['pending' => ['under_review', 'approved', 'rejected', 'withdrawn'], 'under_review' => ['approved', 'rejected', 'withdrawn']];
        if (!in_array($to, $allowed[$a['status']] ?? [], true)) {
            throw new ValidationException(["An application that is " . label($a['status']) . " cannot be changed to " . label($to) . '.']);
        }
        $now = now_utc();
        $db->run('UPDATE volunteer_applications SET status = ?, reviewed_by = ?, reviewed_at = ?, review_notes = COALESCE(?, review_notes), updated_at = ? WHERE id = ?',
            [$to, Auth::id(), $now, $note, $now, $a['id']]);
        $db->insert('application_status_history', ['application_id' => $a['id'], 'from_status' => $a['status'], 'to_status' => $to,
            'changed_by' => Auth::id(), 'changed_at' => $now, 'note' => $note === null ? null : mb_substr($note, 0, 500)]);
    }

    private static function lock(Database $db, int $id): array
    {
        $a = $db->one('SELECT * FROM volunteer_applications WHERE id = ? FOR UPDATE', [$id]);
        if (!$a) {
            \App\Security\Gate::notFound('application');
        }
        return $a;
    }

    public static function setStatus(int $id, string $to, ?string $note): void
    {
        if (!in_array($to, ['under_review', 'rejected', 'withdrawn'], true)) {
            throw new ValidationException(['Unsupported status.']);
        }
        Database::get()->transaction(function (Database $db) use ($id, $to, $note) {
            $a = self::lock($db, $id);
            self::transition($db, $a, $to, $note ?: null);
            AuditLogger::record($to === 'rejected' ? 'application.reject' : 'application.status_change', 'success',
                'volunteer_application', $id, ['from' => $a['status'], 'to' => $to]);
        });
    }

    /**
     * Approve: link an existing volunteer with the same email (only if the reviewer confirms) or create one;
     * create or link the login account; copy skills and availability; issue an activation link.
     */
    public static function approve(int $id, ?string $note, bool $confirmLinkExisting): array
    {
        return Database::get()->transaction(function (Database $db) use ($id, $note, $confirmLinkExisting) {
            $a = self::lock($db, $id);
            $now = now_utc();
            $existing = $db->one('SELECT id, user_id FROM volunteers WHERE active_email = ? FOR UPDATE', [$a['email']]);
            if ($existing && !$confirmLinkExisting) {
                throw new ValidationException(['A volunteer record already exists with this email address. Confirm that this is the same person to link the application to it.']);
            }
            if ($existing) {
                $vid = (int) $existing['id'];
                $userId = $existing['user_id'] === null ? null : (int) $existing['user_id'];
            } else {
                $vid = $db->insert('volunteers', ['user_id' => null, 'full_name' => $a['full_name'], 'email' => $a['email'], 'phone' => $a['phone'],
                    'postcode' => $a['postcode'], 'status' => 'approved', 'start_date' => today_local(), 'created_at' => $now, 'updated_at' => $now]);
                $db->insert('volunteer_status_history', ['volunteer_id' => $vid, 'from_status' => null, 'to_status' => 'approved',
                    'changed_by' => Auth::id(), 'changed_at' => $now, 'reason' => 'Application approved']);
                AuditLogger::record('volunteer.create', 'success', 'volunteer', $vid, ['from_application' => (string) $id]);
                $userId = null;
            }
            foreach ($db->all('SELECT skill_id FROM application_skills WHERE application_id = ?', [$id]) as $s) {
                $db->run("INSERT IGNORE INTO volunteer_skills (volunteer_id, skill_id, proficiency, source, created_at) VALUES (?, ?, NULL, 'application', ?)",
                    [$vid, $s['skill_id'], $now]);
            }
            foreach ($db->all('SELECT weekday FROM application_available_days WHERE application_id = ?', [$id]) as $dRow) {
                $db->run("INSERT IGNORE INTO volunteer_availability (volunteer_id, weekday, period, updated_at) VALUES (?, ?, 'any', ?)",
                    [$vid, $dRow['weekday'], $now]);
            }
            // Login account: volunteer role only (public registration never grants staff roles).
            $token = null;
            if ($userId === null) {
                $u = $db->one('SELECT id, status FROM users WHERE email = ?', [$a['email']]);
                if ($u) {
                    $userId = (int) $u['id'];
                } else {
                    $userId = $db->insert('users', ['email' => $a['email'], 'status' => 'invited', 'session_version' => 1,
                        'created_by' => Auth::id(), 'created_at' => $now, 'updated_at' => $now]);
                    AuditLogger::record('user.invited', 'success', 'user', $userId, ['role' => 'volunteer']);
                }
                $db->run('UPDATE volunteers SET user_id = ?, updated_at = ? WHERE id = ? AND user_id IS NULL', [$userId, $now, $vid]);
            }
            $db->run('INSERT IGNORE INTO user_roles (user_id, role_id, granted_by, granted_at) VALUES (?, 3, ?, ?)', [$userId, Auth::id(), $now]);
            if ($db->value('SELECT status FROM users WHERE id = ?', [$userId]) === 'invited') {
                $token = Tokens::issue($userId, 'activation', Auth::id());
                Mailer::queue($a['email'], 'volunteer_activation', 'Welcome to the EHF Volunteer Hub',
                    "Dear {$a['full_name']},\n\nThank you for applying to volunteer with Ethnic Health Forum. Your application has been approved.\n\n"
                    . "Please activate your account and choose a password (link valid 48 hours):\n"
                    . rtrim((string) env('APP_URL'), '/') . "/activate?token={$token}\n\nEthnic Health Forum");
            }
            $db->run('UPDATE volunteer_applications SET volunteer_id = ? WHERE id = ?', [$vid, $id]);
            self::transition($db, ['volunteer_id' => $vid] + $a, 'approved', $note ?: null);
            AuditLogger::record('application.approve', 'success', 'volunteer_application', $id,
                ['volunteer' => (string) $vid, 'linked_existing' => $existing ? 'true' : 'false']);
            return ['volunteer_id' => $vid, 'user_id' => $userId, 'activation_issued' => $token !== null];
        });
    }
}
