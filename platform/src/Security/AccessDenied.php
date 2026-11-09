<?php
declare(strict_types=1);

namespace App\Security;

final class AccessDenied extends \RuntimeException
{
    public function __construct(public readonly int $status, public readonly array $details = [])
    {
        parent::__construct('Access denied', $status);
    }
}
