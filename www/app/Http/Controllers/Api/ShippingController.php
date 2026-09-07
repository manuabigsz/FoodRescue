<?php

namespace App\Http\Controllers\Api;

use App\Enums\ShippingRequestStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Shipping\CreateShippingRequestRequest;
use App\Http\Requests\Api\Shipping\StoreShippingOfferRequest;
use App\Http\Resources\ShippingOfferResource;
use App\Http\Resources\ShippingRequestResource;
use App\Http\Resources\TradeResource;
use App\Models\ShippingOffer;
use App\Models\ShippingRequest;
use App\Models\Trade;
use App\Services\Logistics\TradeLogistics;
use App\UserRole;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ShippingController extends Controller
{
    public function store(CreateShippingRequestRequest $request, Trade $trade, TradeLogistics $logistics): JsonResponse
    {
        $shipping = $logistics->createShippingRequest($request->user(), $trade, $request->validated());

        return (new ShippingRequestResource($shipping))->response()->setStatusCode(201);
    }

    public function show(Trade $trade): ShippingRequestResource
    {
        $user = request()->user();
        abort_unless($trade->buyer_id === $user->id || $trade->producer_id === $user->id || $user->hasAnyRole([UserRole::Carrier->value, UserRole::Admin->value]), 403);
        $shipping = $trade->shippingRequest()->with(['selectedOffer.carrier.roles', 'offers.carrier.roles'])->firstOrFail();

        return new ShippingRequestResource($shipping);
    }

    public function index(): AnonymousResourceCollection
    {
        $user = request()->user();
        abort_unless($user->hasRole(UserRole::Carrier->value), 403);

        $requests = ShippingRequest::query()
            ->where('status', ShippingRequestStatus::Quoting->value)
            ->where('quotation_expires_at', '>', now())
            ->with('trade.surplusLot.agriculturalProduct')
            ->latest()
            ->paginate(20);

        return ShippingRequestResource::collection($requests);
    }

    public function offer(StoreShippingOfferRequest $request, ShippingRequest $shippingRequest, TradeLogistics $logistics): JsonResponse
    {
        abort_unless($request->user()->hasRole(UserRole::Carrier->value), 403);
        $offer = $logistics->createOffer($request->user(), $shippingRequest, $request->validated());

        return (new ShippingOfferResource($offer))->response()->setStatusCode(201);
    }

    public function offers(Trade $trade): AnonymousResourceCollection
    {
        $user = request()->user();
        abort_unless($trade->buyer_id === $user->id || $trade->producer_id === $user->id || $user->hasRole(UserRole::Admin->value), 403);
        $shipping = $trade->shippingRequest()->firstOrFail();

        return ShippingOfferResource::collection($shipping->offers()->with('carrier.roles')->latest()->get());
    }

    public function select(Trade $trade, ShippingOffer $shippingOffer, TradeLogistics $logistics): TradeResource
    {
        $user = request()->user();

        return new TradeResource($logistics->selectOffer($user, $trade, $shippingOffer));
    }

    public function ngoManaged(CreateShippingRequestRequest $request, Trade $trade, TradeLogistics $logistics): TradeResource
    {
        abort_unless($request->user()->hasRole(UserRole::Ngo->value), 403);

        return new TradeResource($logistics->recipientManaged($request->user(), $trade, $request->validated()));
    }

    public function buyerManaged(CreateShippingRequestRequest $request, Trade $trade, TradeLogistics $logistics): TradeResource
    {
        abort_unless($request->user()->hasRole(UserRole::Buyer->value), 403);

        return new TradeResource($logistics->buyerManaged($request->user(), $trade, $request->validated()));
    }
}
