<?php

namespace App\Enums;

enum BlockchainTransactionType: string
{
    case InitializeTrade = 'initialize_trade';
    case FundTrade = 'fund_trade';
    case SettleTrade = 'settle_trade';
    case CancelTrade = 'cancel_trade';
    case CreateRescueProof = 'create_rescue_proof';
    case MarkReadyForPickup = 'mark_ready_for_pickup';
    case ConfirmPickup = 'confirm_pickup';
    case MarkDelivered = 'mark_delivered';
}
