<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Blockchain\ConfirmDeliveryRequest;
use App\Http\Resources\TradeResource;
use App\Models\Trade;
use App\Services\Blockchain\BlockchainDeliveryService;
use App\Services\Logistics\TradeDelivery;
use Illuminate\Http\JsonResponse;

class DeliveryController extends Controller
{
    public function prepareReadyForPickup(Trade $trade, BlockchainDeliveryService $delivery): JsonResponse
    {
        return response()->json(['data' => $delivery->prepare(request()->user(), $trade, 'ready-for-pickup')]);
    }

    public function readyForPickup(ConfirmDeliveryRequest $request, Trade $trade, BlockchainDeliveryService $delivery, TradeDelivery $legacyDelivery): TradeResource
    {
        if (! $trade->blockchainAccount()->exists()) {
            return new TradeResource($legacyDelivery->markReadyForPickup($request->user(), $trade));
        }

        return new TradeResource($delivery->confirm($request->user(), $trade, 'ready-for-pickup', $request->validated()));
    }

    public function preparePickup(Trade $trade, BlockchainDeliveryService $delivery): JsonResponse
    {
        return response()->json(['data' => $delivery->prepare(request()->user(), $trade, 'pickup')]);
    }

    public function pickup(ConfirmDeliveryRequest $request, Trade $trade, BlockchainDeliveryService $delivery, TradeDelivery $legacyDelivery): TradeResource
    {
        if (! $trade->blockchainAccount()->exists()) {
            return new TradeResource($legacyDelivery->confirmPickup($request->user(), $trade));
        }

        return new TradeResource($delivery->confirm($request->user(), $trade, 'pickup', $request->validated()));
    }

    public function prepareDelivered(Trade $trade, BlockchainDeliveryService $delivery): JsonResponse
    {
        return response()->json(['data' => $delivery->prepare(request()->user(), $trade, 'delivered')]);
    }

    public function delivered(ConfirmDeliveryRequest $request, Trade $trade, BlockchainDeliveryService $delivery, TradeDelivery $legacyDelivery): TradeResource
    {
        if (! $trade->blockchainAccount()->exists()) {
            return new TradeResource($legacyDelivery->markDelivered($request->user(), $trade));
        }

        return new TradeResource($delivery->confirm($request->user(), $trade, 'delivered', $request->validated()));
    }
}
