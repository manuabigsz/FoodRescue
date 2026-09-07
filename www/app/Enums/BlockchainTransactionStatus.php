<?php

namespace App\Enums;

enum BlockchainTransactionStatus: string
{
    case Confirmed = 'confirmed';
    case Finalized = 'finalized';
}
