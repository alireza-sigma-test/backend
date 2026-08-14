<?php

namespace App\Enums;

enum UserRole: string
{
    case Speaker = 'speaker';
    case Reviewer = 'reviewer';
    case Admin = 'admin';

    /** @return string[] */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
