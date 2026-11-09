<?php
declare(strict_types=1);

namespace App\Core;

final class Csrf
{
    public static function token(): string
    {
        $t = Session::get('_csrf');
        if (!is_string($t)) {
            $t = bin2hex(random_bytes(32));
            Session::set('_csrf', $t);
        }
        return $t;
    }

    public static function field(): string
    {
        return '<input type="hidden" name="_csrf" value="' . e(self::token()) . '">';
    }

    public static function valid(Request $r): bool
    {
        $sent = $r->post['_csrf'] ?? $r->header('x-csrf-token') ?? '';
        $known = Session::get('_csrf');
        return is_string($sent) && is_string($known) && $sent !== '' && hash_equals($known, $sent);
    }
}
