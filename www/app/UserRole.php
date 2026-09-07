<?php

namespace App;

enum UserRole: string
{
    case Admin = 'admin';
    case Producer = 'producer';
    case Buyer = 'buyer';
    case Carrier = 'carrier';
    case Ngo = 'ngo';

    /** @return list<string> */
    public static function publicValues(): array
    {
        return [self::Producer->value, self::Buyer->value, self::Carrier->value, self::Ngo->value];
    }
}
