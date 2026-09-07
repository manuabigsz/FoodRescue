<?php

namespace App\Services\Reputation;

use App\Enums\TradeStatus;
use App\Models\Rating;
use App\Models\Trade;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RatingService
{
    public function create(User $reviewer, Trade $trade, User $target, int $rating, ?string $comment): Rating
    {
        return DB::transaction(function () use ($reviewer, $trade, $target, $rating, $comment): Rating {
            $trade = Trade::query()
                ->with(['shippingRequest.selectedOffer'])
                ->lockForUpdate()
                ->findOrFail($trade->id);

            if ($trade->status !== TradeStatus::Completed) {
                throw ValidationException::withMessages(['trade' => 'Avaliações só são permitidas após a conclusão do trade.']);
            }

            if ($reviewer->is($target)) {
                throw ValidationException::withMessages(['target_user_id' => 'Não é possível avaliar a própria conta.']);
            }

            if (! $this->isAllowedPair($trade, $reviewer->id, $target->id)) {
                throw ValidationException::withMessages(['target_user_id' => 'A avaliação não é permitida entre estes participantes do trade.']);
            }

            if (Rating::query()
                ->where('trade_id', $trade->id)
                ->where('reviewer_id', $reviewer->id)
                ->where('target_user_id', $target->id)
                ->exists()) {
                throw ValidationException::withMessages(['target_user_id' => 'Este participante já foi avaliado por você neste trade.']);
            }

            return Rating::query()->create([
                'trade_id' => $trade->id,
                'reviewer_id' => $reviewer->id,
                'target_user_id' => $target->id,
                'rating' => $rating,
                'comment' => $comment,
            ]);
        }, 3);
    }

    private function isAllowedPair(Trade $trade, int $reviewerId, int $targetId): bool
    {
        $recipientId = (int) $trade->buyer_id;
        $producerId = (int) $trade->producer_id;
        $carrierId = $trade->shippingRequest?->selectedOffer?->carrier_id;

        $pairs = [
            [$producerId, $recipientId],
            [$recipientId, $producerId],
        ];

        if ($carrierId !== null) {
            $carrierId = (int) $carrierId;
            $pairs[] = [$recipientId, $carrierId];
            $pairs[] = [$carrierId, $recipientId];
        }

        foreach ($pairs as [$from, $to]) {
            if ($reviewerId === $from && $targetId === $to) {
                return true;
            }
        }

        return false;
    }
}
