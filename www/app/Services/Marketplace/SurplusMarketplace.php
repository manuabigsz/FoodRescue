<?php

namespace App\Services\Marketplace;

use App\Enums\OfferStatus;
use App\Enums\SurplusStatus;
use App\Enums\TradeStatus;
use App\Models\Offer;
use App\Models\SurplusLot;
use App\Models\Trade;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;

class SurplusMarketplace
{
    /** @param array<string, mixed> $data */
    public function createLot(User $producer, array $data): SurplusLot
    {
        return DB::transaction(function () use ($producer, $data): SurplusLot {
            $lot = SurplusLot::create($data + [
                'producer_id' => $producer->id,
                'status' => SurplusStatus::Open,
            ]);

            return $lot->load(['producer.roles', 'agriculturalProduct', 'qualityGrade']);
        });
    }

    /** @param array<string, mixed> $data */
    public function updateLot(SurplusLot $lot, array $data): SurplusLot
    {
        return DB::transaction(function () use ($lot, $data): SurplusLot {
            $locked = SurplusLot::whereKey($lot->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->status === SurplusStatus::Open, 409, 'Somente lotes abertos podem ser alterados.');
            $locked->fill($data);
            if ($locked->minimum_price !== null && BigDecimal::of($locked->minimum_price)->isGreaterThan($locked->asking_price)) {
                abort(422, 'O preço mínimo não pode ser maior que o preço pedido.');
            }
            $locked->save();

            return $locked->load(['producer.roles', 'agriculturalProduct', 'qualityGrade']);
        }, 3);
    }

    public function cancelLot(SurplusLot $lot): SurplusLot
    {
        return DB::transaction(function () use ($lot): SurplusLot {
            $locked = SurplusLot::whereKey($lot->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->status === SurplusStatus::Open, 409, 'Somente lotes abertos podem ser cancelados.');
            $locked->status = SurplusStatus::Cancelled;
            $locked->save();
            $locked->offers()->where('status', OfferStatus::Pending->value)->update([
                'status' => OfferStatus::Expired->value,
                'responded_at' => now(),
            ]);

            return $locked->load(['producer.roles', 'agriculturalProduct', 'qualityGrade']);
        }, 3);
    }

    public function createOffer(User $buyer, SurplusLot $lot, string $amount, ?string $expiresAt): Offer
    {
        return DB::transaction(function () use ($buyer, $lot, $amount, $expiresAt): Offer {
            $locked = SurplusLot::whereKey($lot->id)->lockForUpdate()->firstOrFail();
            $this->assertLotAvailable($locked);
            abort_if($locked->producer_id === $buyer->id, 409, 'O produtor não pode fazer oferta no próprio lote.');
            $locked->offers()->where('status', OfferStatus::Pending->value)
                ->where('expires_at', '<=', now())->update(['status' => OfferStatus::Expired->value, 'responded_at' => now()]);
            abort_if($locked->offers()->where('buyer_id', $buyer->id)->where('status', OfferStatus::Pending->value)->exists(), 409, 'Já existe uma oferta pendente deste comprador.');

            return $locked->offers()->create([
                'buyer_id' => $buyer->id,
                'amount' => $amount,
                'status' => OfferStatus::Pending,
                'expires_at' => $expiresAt,
            ])->load('buyer.roles');
        }, 3);
    }

    public function rejectOffer(Offer $offer): Offer
    {
        return DB::transaction(function () use ($offer): Offer {
            $locked = Offer::whereKey($offer->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->status === OfferStatus::Pending, 409, 'A oferta não está pendente.');
            $locked->status = OfferStatus::Rejected;
            $locked->responded_at = now();
            $locked->save();

            return $locked->load('buyer.roles');
        }, 3);
    }

    public function acceptOffer(Offer $offer): Trade
    {
        return DB::transaction(function () use ($offer): Trade {
            $lot = SurplusLot::whereKey($offer->surplus_lot_id)->lockForUpdate()->firstOrFail();
            $lockedOffer = Offer::whereKey($offer->id)->lockForUpdate()->firstOrFail();
            abort_unless($lockedOffer->status === OfferStatus::Pending, 409, 'A oferta não está pendente.');
            abort_if($lockedOffer->expires_at?->isPast(), 409, 'A oferta expirou.');
            $this->assertLotAvailable($lot);

            $lockedOffer->update(['status' => OfferStatus::Accepted, 'responded_at' => now()]);
            $lot->offers()->whereKeyNot($lockedOffer->id)->where('status', OfferStatus::Pending->value)->update([
                'status' => OfferStatus::Rejected->value,
                'responded_at' => now(),
            ]);

            return $this->reserveTrade($lot, $lockedOffer->buyer_id, (string) $lockedOffer->amount, $lockedOffer->id);
        }, 3);
    }

    public function buyNow(User $buyer, SurplusLot $lot): Trade
    {
        return DB::transaction(function () use ($buyer, $lot): Trade {
            $locked = SurplusLot::whereKey($lot->id)->lockForUpdate()->firstOrFail();
            $this->assertLotAvailable($locked);
            abort_if($locked->producer_id === $buyer->id, 409, 'O produtor não pode comprar o próprio lote.');

            $locked->offers()->where('status', OfferStatus::Pending->value)->update([
                'status' => OfferStatus::Rejected->value,
                'responded_at' => now(),
            ]);

            return $this->reserveTrade($locked, $buyer->id, (string) $locked->asking_price, null);
        }, 3);
    }

    public function expireLotsAndOffers(): int
    {
        $count = 0;
        SurplusLot::where('status', SurplusStatus::Open->value)->where('available_until', '<=', now())
            ->pluck('id')->each(function (int $id) use (&$count): void {
                DB::transaction(function () use ($id, &$count): void {
                    $lot = SurplusLot::whereKey($id)->lockForUpdate()->first();
                    if (! $lot || $lot->status !== SurplusStatus::Open || $lot->available_until->isFuture()) {
                        return;
                    }
                    $lot->update(['status' => SurplusStatus::Expired]);
                    $lot->offers()->where('status', OfferStatus::Pending->value)->update([
                        'status' => OfferStatus::Expired->value, 'responded_at' => now(),
                    ]);
                    $count++;
                }, 3);
            });
        Offer::where('status', OfferStatus::Pending->value)->where('expires_at', '<=', now())
            ->update(['status' => OfferStatus::Expired->value, 'responded_at' => now()]);

        return $count;
    }

    private function reserveTrade(SurplusLot $lot, int $buyerId, string $productAmount, ?int $offerId): Trade
    {
        abort_unless(BigDecimal::of($productAmount)->isPositive(), 422, 'Uma compra comercial exige valor de produto positivo.');
        $fee = (string) BigDecimal::of($productAmount)->multipliedBy('0.02')->toScale(6, RoundingMode::Down);
        $trade = Trade::create([
            'surplus_lot_id' => $lot->id,
            'producer_id' => $lot->producer_id,
            'buyer_id' => $buyerId,
            'accepted_offer_id' => $offerId,
            'product_amount' => $productAmount,
            'shipping_amount' => 0,
            'protocol_fee' => $fee,
            'status' => TradeStatus::Reserved,
            'is_donation' => false,
        ]);
        $lot->update(['status' => SurplusStatus::Reserved, 'reserved_at' => now()]);

        return $trade;
    }

    private function assertLotAvailable(SurplusLot $lot): void
    {
        abort_unless($lot->status === SurplusStatus::Open, 409, 'O lote não está disponível.');
        abort_if($lot->available_until->isPast(), 409, 'O lote expirou.');
    }
}
