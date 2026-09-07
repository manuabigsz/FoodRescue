<?php

namespace App\Enums;

enum ShippingOfferStatus: string
{
    case Pending = 'pending';
    case Selected = 'selected';
    case Rejected = 'rejected';
    case Expired = 'expired';
}
