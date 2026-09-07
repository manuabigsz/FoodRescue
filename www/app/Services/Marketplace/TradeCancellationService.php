<?php

namespace App\Services\Marketplace;

use App\Enums\ShippingOfferStatus;
use App\Enums\ShippingRequestStatus;
use App\Enums\SurplusStatus;
use App\Enums\TradeStatus;
use App\Models\Trade;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class TradeCancellationService
{
    public function cancelOffChain(User $actor, Trade $trade, ?string $reason = null): Trade
    {
        return DB::transaction(function () use ($actor, $trade, $reason): Trade {
            $locked = Trade::whereKey($trade->id)->lockForUpdate()
                ->with(['shippingRequest.offers', 'blockchainAccount'])->firstOrFail();

            $this->assertParticipant($actor, $locked);
            abort_if($locked->blockchainAccount !== null, 409, 'O trade já possui estado on-chain; use o fluxo de cancelamento blockchain.');
            abort_if($locked->blockchain_preparation !== null, 409, 'Uma instrução on-chain já foi preparada; confirme o estado blockchain antes de cancelar.');
            abort_unless(in_array($locked->status, [
                TradeStatus::Reserved, TradeStatus::ShippingQuotation, TradeStatus::CarrierSelected,
                TradeStatus::BuyerManaged, TradeStatus::WaitingPayment,
            ], true), 409, 'O trade não pode mais ser cancelado sem operação on-chain.');

            $this->finalizeBackendCancellation($actor, $locked, $reason);

            return $locked->fresh()->load(['shippingRequest.selectedOffer.carrier.roles', 'blockchainAccount.transactions']);
        }, 3);
    }

    public function finalizeBackendCancellation(User $actor, Trade $trade, ?string $reason = null): void
    {
        $trade->update([
            'status' => TradeStatus::Cancelled,
            'cancelled_by_id' => $actor->id,
            'cancellation_reason' => $reason,
            'cancelled_at' => now(),
            'payment_expires_at' => null,
        ]);

        $trade->surplusLot()->update([
            'status' => $actor->id === $trade->buyer_id
                ? ($trade->surplusLot->available_until->isPast() ? SurplusStatus::Expired : SurplusStatus::Open)
                : SurplusStatus::Cancelled,
            'reserved_at' => null,
        ]);

        $shipping = $trade->shippingRequest()->lockForUpdate()->first();
        if ($shipping !== null) {
            $shipping->offers()->whereIn('status', [
                ShippingOfferStatus::Pending->value, ShippingOfferStatus::Selected->value,
            ])->update(['status' => ShippingOfferStatus::Expired->value]);
            $shipping->update(['status' => ShippingRequestStatus::Expired]);
        }
    }

    public function assertParticipant(User $actor, Trade $trade): void
    {
        abort_unless(in_array($actor->id, [$trade->buyer_id, $trade->producer_id], true), 403);
    }
}
