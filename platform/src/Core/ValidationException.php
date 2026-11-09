<?php
declare(strict_types=1);

namespace App\Core;

/** Thrown for user-correctable input or business-rule errors. Messages are safe to display. */
final class ValidationException extends \RuntimeException
{
    public function __construct(public readonly array $errors)
    {
        parent::__construct(implode(' ', $errors));
    }
}
