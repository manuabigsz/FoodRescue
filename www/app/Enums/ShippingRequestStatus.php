<?php

namespace App\Enums;

enum ShippingRequestStatus: string
{
    case Quoting = 'quoting';
    case CarrierSelected = 'carrier_selected';
    case BuyerManaged = 'buyer_managed';
    case Expired = 'expired';
}
