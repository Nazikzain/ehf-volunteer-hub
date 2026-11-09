<?php
declare(strict_types=1);

namespace App\Security;

use App\Audit\AuditLogger;
use App\Core\HttpException;

final class Gate
{
    private static ?array $map = null;

    public static function map(): array
    {
        return self::$map ??= require APP_ROOT . '/config/permissions.php';
    }

    public static function allows(string $permission): bool
    {
        $map = self::map();
        if (!array_key_exists($permission, $map)) {
            throw new \InvalidArgumentException("Unknown permission {$permission}");
        }
        if (!Auth::check()) {
            return false;
        }
        if (Auth::isOwner()) {
            return true;
        }
        $roles = $map[$permission];
        return is_array($roles) && array_intersect($roles, Auth::roles()) !== [];
    }

    /** 403 when the permission is missing. Audited as access.denied. */
    public static function authorize(string $permission): void
    {
        if (!self::allows($permission)) {
            self::deny(403, ['permission' => $permission]);
        }
    }

    /** Out-of-scope resource: 404 (not 403) so records cannot be discovered (Design §8.1). */
    public static function notFound(string $what = 'resource'): never
    {
        self::deny(404, ['scope' => $what]);
    }

    public static function deny(int $status, array $details): never
    {
        throw new AccessDenied($status, $details);
    }

    public static function logDenial(AccessDenied $e, string $route): void
    {
        AuditLogger::record('access.denied', 'denied', null, null, $e->details + ['route' => $route, 'status' => $e->status]);
    }
}
