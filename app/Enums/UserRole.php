<?php

namespace App\Enums;

enum UserRole: string
{
    case LEARNER = 'learner';
    case TEACHER = 'teacher';
    case ACADEMIC = 'academic';
    case SUPPORT = 'support';
    case FINANCE = 'finance';
    case ADMIN = 'admin';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $role) => $role->value, self::cases());
    }
}
