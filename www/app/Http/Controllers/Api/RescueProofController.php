<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Rescue\ConfirmProducerRescueProofRequest;
use App\Http\Requests\Api\Rescue\ConfirmRescueProofRequest;
use App\Http\Resources\RescueProofResource;
use App\Models\Trade;
use App\Services\Rescue\RescueProofService;
use App\UserRole;
use Illuminate\Http\JsonResponse;

class RescueProofController extends Controller
{
    public function prepare(Trade $trade, RescueProofService $proofs): JsonResponse
    {
        $user = request()->user();
        abort_unless($user->hasRole(UserRole::Ngo->value), 403);

        return response()->json(['data' => $proofs->prepare($user, $trade)]);
    }

    public function confirm(ConfirmRescueProofRequest $request, Trade $trade, RescueProofService $proofs): RescueProofResource
    {
        abort_unless($request->user()->hasRole(UserRole::Ngo->value), 403);

        return new RescueProofResource($proofs->confirm($request->user(), $trade, $request->validated()));
    }

    public function prepareProducer(Trade $trade, RescueProofService $proofs): JsonResponse
    {
        return response()->json(['data' => $proofs->prepareProducerConfirmation(request()->user(), $trade)]);
    }

    public function confirmProducer(ConfirmProducerRescueProofRequest $request, Trade $trade, RescueProofService $proofs): RescueProofResource
    {
        return new RescueProofResource($proofs->confirmProducerConfirmation($request->user(), $trade, $request->validated()));
    }

    public function show(Trade $trade): RescueProofResource
    {
        $user = request()->user();
        abort_unless($trade->producer_id === $user->id || $trade->buyer_id === $user->id || $user->hasRole(UserRole::Admin->value), 403);

        return new RescueProofResource($trade->rescueProof()->firstOrFail());
    }
}
