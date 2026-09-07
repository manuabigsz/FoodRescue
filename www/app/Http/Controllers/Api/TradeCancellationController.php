<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\CancelTradeRequest;
use App\Http\Resources\TradeResource;
use App\Models\Trade;
use App\Services\Marketplace\TradeCancellationService;

class TradeCancellationController extends Controller
{
    public function cancel(CancelTradeRequest $request, Trade $trade, TradeCancellationService $cancellations): TradeResource
    {
        return new TradeResource(
            $cancellations->cancelOffChain($request->user(), $trade, $request->validated('reason'))
        );
    }
}
