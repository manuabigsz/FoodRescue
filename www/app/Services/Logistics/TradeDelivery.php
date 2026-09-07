<?php

namespace App\Services\Logistics;

use App\Enums\TradeStatus;
use App\Models\Trade;
use App\Models\User;
use App\UserRole;
use Illuminate\Support\Facades\DB;

class TradeDelivery
{
    public function markReadyForPickup(User $producer, Trade $trade): Trade
    {
        return DB::transaction(function () use ($producer, $trade): Trade {
            $locked = $this->lock($trade);
            abort_unless($locked->producer_id === $producer->id && $producer->hasRole(UserRole::Producer->value), 403);
            abort_unless($locked->status === TradeStatus::Funded, 409, 'O trade precisa estar financiado antes de ficar pronto para coleta.');

            $locked->update([
                'status' => TradeStatus::ReadyForPickup,
                'ready_for_pickup_at' => now(),
            ]);

            return $this->fresh($locked);
        }, 3);
    }

    public function confirmPickup(User $actor, Trade $trade): Trade
    {
        return DB::transaction(function () use ($actor, $trade): Trade {
            $locked = $this->lock($trade);
            abort_unless($locked->status === TradeStatus::ReadyForPickup, 409, 'O trade não está pronto para coleta.');
            $this->assertTransportActor($actor, $locked);

            $locked->update([
                'status' => TradeStatus::InTransit,
                'picked_up_at' => now(),
            ]);

            return $this->fresh($locked);
        }, 3);
    }

    public function markDelivered(User $actor, Trade $trade): Trade
    {
        return DB::transaction(function () use ($actor, $trade): Trade {
            $locked = $this->lock($trade);
            abort_unless($locked->status === TradeStatus::InTransit, 409, 'O trade não está em trânsito.');
            $this->assertTransportActor($actor, $locked);

            $locked->update([
                'status' => TradeStatus::Delivered,
                'delivered_at' => now(),
            ]);

            return $this->fresh($locked);
        }, 3);
    }

    private function lock(Trade $trade): Trade
    {
        return Trade::whereKey($trade->id)
            ->lockForUpdate()
            ->with(['shippingRequest.selectedOffer.carrier', 'blockchainAccount'])
            ->firstOrFail();
    }

    private function assertTransportActor(User $actor, Trade $trade): void
    {
        $carrier = $trade->shippingRequest?->selectedOffer?->carrier;

        if ($carrier !== null) {
            abort_unless($actor->id === $carrier->id && $actor->hasRole(UserRole::Carrier->value), 403);

            return;
        }

        if ($trade->is_donation) {
            abort_unless($actor->id === $trade->buyer_id && $actor->hasRole(UserRole::Ngo->value), 403);

            return;
        }

        abort_unless($actor->id === $trade->buyer_id && $actor->hasRole(UserRole::Buyer->value), 403);
    }

    private function fresh(Trade $trade): Trade
    {
        return $trade->fresh()->load([
            'shippingRequest.selectedOffer.carrier.roles',
            'blockchainAccount.transactions',
        ]);
    }
}
