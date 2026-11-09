<?php
declare(strict_types=1);

use App\Audit\Canonical;
use App\Core\Migrator;
use App\Core\Response;
use App\Security\PasswordPolicy;
use Tests\T;

T::test('unit', 'canonical: details keys sorted recursively, numbers become strings', function () {
    $n = Canonical::normaliseDetails(['b' => 2, 'a' => ['z' => 1, 'y' => true], 'list' => [3, 'x']]);
    T::eq('{"a":{"y":true,"z":"1"},"b":"2","list":["3","x"]}', Canonical::detailsText($n));
    T::eq(null, Canonical::normaliseDetails([]));
});

T::test('unit', 'canonical: escaping (no slash/unicode escaping, control chars escaped)', function () {
    $t = Canonical::detailsText(Canonical::normaliseDetails(['k' => "a/b é \u{2028} \"q\" \\ \n \x01"]));
    T::eq('{"k":"a/b é ' . "\u{2028}" . ' \"q\" \\\\ \n \u0001"}', $t);
});

T::test('unit', 'canonical: row encoding is the 18-element array with fixed formats', function () {
    $row = ['canon_version' => 1, 'seq' => 42, 'occurred_at' => '2026-11-09 14:03:12.123456', 'recorded_at' => '2026-11-09 14:03:12.5',
        'source' => 'direct', 'fallback_ref' => null, 'actor_user_id' => 7, 'actor_email' => 'admin@example.test', 'actor_role' => 'organisation_admin',
        'action' => 'application.approve', 'outcome' => 'success', 'target_type' => 'volunteer_application', 'target_id' => 118,
        'ip' => inet_pton('203.0.113.5'), 'request_id' => '9f2c4a7e1b0d3c55', 'details' => '{"from":"under_review","to":"approved"}',
        'key_id' => 1, 'prev_hash' => str_repeat("\0", 32)];
    $enc = Canonical::encodeRow($row);
    $arr = json_decode($enc, true);
    T::eq(18, count($arr));
    T::eq('42', $arr[1]);
    T::eq('2026-11-09T14:03:12.123456Z', $arr[2]);
    T::eq('2026-11-09T14:03:12.500000Z', $arr[3]);
    T::eq('203.0.113.5', $arr[13]);
    T::eq(Canonical::GENESIS, $arr[17]);
    T::assert(!str_contains($enc, ' '), 'no whitespace outside strings');
    T::eq('0', Canonical::intStr(0));
});

T::test('unit', 'canonical: details containing JSON numbers fail re-canonicalisation', function () {
    T::throws(InvalidArgumentException::class, fn() => Canonical::recanonicaliseDetails('{"a":1}'));
    T::eq('{"a":"1"}', Canonical::recanonicaliseDetails('{"a":"1"}'));
});

T::test('unit', 'migrator: splits statements and honours DELIMITER blocks', function () {
    $sql = "-- c\nCREATE TABLE a (x INT);\nDELIMITER $$\nCREATE TRIGGER t BEFORE UPDATE ON a FOR EACH ROW\nBEGIN\n  SET NEW.x = 1;\nEND$$\nDELIMITER ;\nSELECT 1;\n";
    $s = Migrator::split($sql);
    T::eq(3, count($s));
    T::assert(str_contains($s[1], 'SET NEW.x = 1;') && str_ends_with($s[1], 'END'), 'trigger body kept intact');
});

T::test('unit', 'password policy: length, common passwords, email name', function () {
    T::assert(PasswordPolicy::check('short', 'a@b.c') !== [], 'short rejected');
    T::assert(PasswordPolicy::check('password123', 'a@b.c') !== [], 'common rejected');
    T::assert(PasswordPolicy::check('jane.smith-Garden-77', 'jane.smith@x.org') !== [], 'contains email name');
    T::eq([], PasswordPolicy::check('Correct-Horse-Battery-9', 'jane@x.org'));
});

T::test('unit', 'CSV export neutralises spreadsheet formula injection', function () {
    $r = Response::csv('x.csv', ['a', 'b'], [['=HYPERLINK("http://evil")', 'ok'], ['+1', '@x']]);
    T::assert(str_contains($r->body, "'=HYPERLINK"), 'leading = escaped');
    T::assert(str_contains($r->body, "'+1") && str_contains($r->body, "'@x"), 'leading + and @ escaped');
});
