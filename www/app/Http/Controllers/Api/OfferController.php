<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Offer\StoreOfferRequest;
use App\Http\Resources\OfferResource;
use App\Http\Resources\TradeResource;
use App\Models\Offer;
use App\Models\SurplusLot;
use App\Services\Marketplace\SurplusMarketplace;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class OfferController extends Controller
{
    public function store(StoreOfferRequest $request, SurplusLot $surplusLot, SurplusMarketplace $marketplace): JsonResponse
    {
        Gate::authorize('create', [Offer::class, $surplusLot]);
        $data = $request->validated();
        $offer = $marketplace->createOffer($request->user(), $surplusLot, (string) $data['amount'], $data['expires_at'] ?? null);

        return (new OfferResource($offer))->response()->setStatusCode(201);
    }

    public function accept(Offer $offer, SurplusMarketplace $marketplace): TradeResource
    {
        Gate::authorize('respond', $offer);

        return new TradeResource($marketplace->acceptOffer($offer));
    }

    public function reject(Offer $offer, SurplusMarketplace $marketplace): OfferResource
    {
        Gate::authorize('respond', $offer);

        return new OfferResource($marketplace->rejectOffer($offer));
    }
}
