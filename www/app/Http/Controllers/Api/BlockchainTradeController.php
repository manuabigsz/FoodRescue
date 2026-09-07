<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Blockchain\ConfirmCancellationRequest;
use App\Http\Requests\Api\Blockchain\ConfirmFundingRequest;
use App\Http\Requests\Api\Blockchain\ConfirmInitializeTradeRequest;
use App\Http\Requests\Api\Blockchain\ConfirmSettlementRequest;
use App\Http\Resources\BlockchainTradeAccountResource;
use App\Http\Resources\TradeResource;
use App\Models\Trade;
use App\Services\Blockchain\BlockchainPaymentService;
use App\UserRole;
use Illuminate\Http\JsonResponse;

class BlockchainTradeController extends Controller
{
    public function prepare(Trade $trade, BlockchainPaymentService $payments): JsonResponse
    {
        $user = request()->user();

        return response()->json(['data' => $payments->prepare($user, $trade)]);
    }

    public function confirmInitialization(ConfirmInitializeTradeRequest $request, Trade $trade, BlockchainPaymentService $payments): JsonResponse
    {
        $account = $payments->confirmInitialization($request->user(), $trade, $request->validated());

        return (new BlockchainTradeAccountResource($account))->response()->setStatusCode(201);
    }

    public function confirmFunding(ConfirmFundingRequest $request, Trade $trade, BlockchainPaymentService $payments): TradeResource
    {
        return new TradeResource($payments->confirmFunding($request->user(), $trade, $request->validated()));
    }

    public function prepareSettlement(Trade $trade, BlockchainPaymentService $payments): JsonResponse
    {
        $user = request()->user();

        return response()->json(['data' => $payments->prepareSettlement($user, $trade)]);
    }

    public function confirmSettlement(ConfirmSettlementRequest $request, Trade $trade, BlockchainPaymentService $payments): TradeResource
    {
        return new TradeResource($payments->confirmSettlement($request->user(), $trade, $request->validated()));
    }

    public function prepareCancellation(Trade $trade, BlockchainPaymentService $payments): JsonResponse
    {
        return response()->json(['data' => $payments->prepareCancellation(request()->user(), $trade)]);
    }

    public function confirmCancellation(ConfirmCancellationRequest $request, Trade $trade, BlockchainPaymentService $payments): TradeResource
    {
        return new TradeResource($payments->confirmCancellation($request->user(), $trade, $request->validated()));
    }

    public function show(Trade $trade): BlockchainTradeAccountResource
    {
        $user = request()->user();
        abort_unless($trade->buyer_id === $user->id || $trade->producer_id === $user->id || $user->hasRole(UserRole::Admin->value), 403);

        return new BlockchainTradeAccountResource($trade->blockchainAccount()->with('transactions')->firstOrFail());
    }
}
