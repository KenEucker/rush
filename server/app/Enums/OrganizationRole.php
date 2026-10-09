<?php

namespace App\Enums;

enum OrganizationRole: string
{
    case Ranger = 'ranger';
    case Management = 'management';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
