<?php

namespace App\Enums;

enum TradeStatus: string
{
    case Reserved = 'reserved';
    case ShippingQuotation = 'shipping_quotation';
    case CarrierSelected = 'carrier_selected';
    case BuyerManaged = 'buyer_managed';
    case WaitingPayment = 'waiting_payment';
    case Funded = 'funded';
    case ReadyForPickup = 'ready_for_pickup';
    case InTransit = 'in_transit';
    case Delivered = 'delivered';
    case ProofPending = 'proof_pending';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
}
