<?php
declare(strict_types=1);

namespace App\Security;

final class PasswordPolicy
{
    private const COMMON = ['password', 'password1', 'password123', '123456789012', 'qwertyuiop12', 'iloveyou1234',
        'welcome12345', 'letmein12345', 'administrator', 'ethnichealth', 'volunteer123', 'changeme1234',
        'passwordpassword', '111111111111', '000000000000', 'abc123456789', 'qwerty123456'];

    /** @return string[] errors */
    public static function check(string $password, string $email): array
    {
        $errors = [];
        $len = mb_strlen($password);
        if ($len < 12) {
            $errors[] = 'Password must be at least 12 characters.';
        }
        if ($len > 128) {
            $errors[] = 'Password must be at most 128 characters.';
        }
        $lower = mb_strtolower($password);
        if (in_array($lower, self::COMMON, true) || str_contains($lower, 'ethnichealth')) {
            $errors[] = 'This password is too common. Please choose another.';
        }
        $local = mb_strtolower(strstr($email, '@', true) ?: $email);
        if ($local !== '' && mb_strlen($local) >= 4 && str_contains($lower, $local)) {
            $errors[] = 'Password must not contain your email name.';
        }
        return $errors;
    }
}
