<?php

namespace App\Http\Resources;

use Brick\Math\BigDecimal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TradeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'surplus_lot_id' => $this->surplus_lot_id,
            'surplus_lot' => new SurplusLotResource($this->whenLoaded('surplusLot')),
            'producer_id' => $this->producer_id,
            'buyer_id' => $this->buyer_id,
            'recipient_id' => $this->buyer_id,
            'recipient_type' => $this->is_donation ? 'ngo' : 'buyer',
            'cancelled_by_id' => $this->cancelled_by_id,
            'cancellation_reason' => $this->cancellation_reason,
            'accepted_offer_id' => $this->accepted_offer_id,
            'product_amount' => $this->product_amount,
            'shipping_amount' => $this->shipping_amount,
            'protocol_fee' => $this->protocol_fee,
            'status' => $this->status->value,
            'is_donation' => $this->is_donation,
            'donation_accepted_at' => $this->donation_accepted_at?->toISOString(),
            'buyer_total' => (string) BigDecimal::of($this->product_amount)->plus($this->shipping_amount)->toScale(6),
            'payment_expires_at' => $this->payment_expires_at?->toISOString(),
            'ready_for_pickup_at' => $this->ready_for_pickup_at?->toISOString(),
            'picked_up_at' => $this->picked_up_at?->toISOString(),
            'delivered_at' => $this->delivered_at?->toISOString(),
            'proof_pending_at' => $this->proof_pending_at?->toISOString(),
            'completed_at' => $this->completed_at?->toISOString(),
            'cancelled_at' => $this->cancelled_at?->toISOString(),
            'shipping_request' => new ShippingRequestResource($this->whenLoaded('shippingRequest')),
            'blockchain' => new BlockchainTradeAccountResource($this->whenLoaded('blockchainAccount')),
            'rescue_proof' => new RescueProofResource($this->whenLoaded('rescueProof')),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
