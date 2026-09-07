<?php

namespace App\Enums;

enum LogisticsMode: string
{
    case BuyerPickup = 'buyer_pickup';
    case ProducerDelivery = 'producer_delivery';
    case ThirdPartyCarrier = 'third_party_carrier';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** @return list<string> */
    public static function supportedValues(): array
    {
        return [
            self::BuyerPickup->value,
            self::ThirdPartyCarrier->value,
        ];
    }
}
