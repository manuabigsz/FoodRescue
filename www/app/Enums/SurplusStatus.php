<?php

namespace App\Enums;

enum SurplusStatus: string
{
    case Open = 'open';
    case Reserved = 'reserved';
    case Sold = 'sold';
    case Donated = 'donated';
    case Expired = 'expired';
    case Cancelled = 'cancelled';
}
