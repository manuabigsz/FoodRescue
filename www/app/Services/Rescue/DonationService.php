<?php

namespace App\Services\Rescue;

use App\Enums\OfferStatus;
use App\Enums\SurplusStatus;
use App\Enums\TradeStatus;
use App\Models\SurplusLot;
use App\Models\Trade;
use App\Models\User;
use App\UserRole;
use Illuminate\Support\Facades\DB;

class DonationService
{
    public function accept(User $ngo, SurplusLot $lot): Trade
    {
        return DB::transaction(function () use ($ngo, $lot): Trade {
            abort_unless($ngo->hasRole(UserRole::Ngo->value), 403);

            $locked = SurplusLot::whereKey($lot->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->status === SurplusStatus::Open, 409, 'O lote não está disponível.');
            abort_unless($locked->donation_eligible, 409, 'O lote não está disponível para doação.');
            abort_if($locked->available_until->isPast(), 409, 'O lote expirou.');
            abort_if($locked->producer_id === $ngo->id, 409, 'O produtor não pode aceitar a própria doação.');

            $locked->offers()->where('status', OfferStatus::Pending->value)->update([
                'status' => OfferStatus::Rejected->value,
                'responded_at' => now(),
            ]);

            $trade = Trade::create([
                'surplus_lot_id' => $locked->id,
                'producer_id' => $locked->producer_id,
                'buyer_id' => $ngo->id,
                'accepted_offer_id' => null,
                'product_amount' => 0,
                'shipping_amount' => 0,
                'protocol_fee' => 0,
                'status' => TradeStatus::Reserved,
                'is_donation' => true,
                'donation_accepted_at' => now(),
            ]);

            $locked->update([
                'status' => SurplusStatus::Reserved,
                'reserved_at' => now(),
            ]);

            return $trade->load(['producer.roles', 'buyer.roles', 'surplusLot.agriculturalProduct']);
        }, 3);
    }
}
