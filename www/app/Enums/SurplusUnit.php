<?php

namespace App\Enums;

enum SurplusUnit: string
{
    case Kilogram = 'kg';
    case Ton = 't';
    case Box = 'box';
    case Unit = 'unit';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
