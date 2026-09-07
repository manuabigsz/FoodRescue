<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Blockchain\ConfirmProtocolConfigRequest;
use App\Http\Requests\Api\Blockchain\PrepareProtocolConfigRequest;
use App\Http\Resources\BlockchainProtocolConfigResource;
use App\Services\Blockchain\ProtocolConfigService;
use Illuminate\Http\JsonResponse;

class AdminProtocolController extends Controller
{
    public function prepare(PrepareProtocolConfigRequest $request, ProtocolConfigService $protocol): JsonResponse
    {
        return response()->json(['data' => $protocol->prepareInitialization($request->validated())]);
    }

    public function confirm(ConfirmProtocolConfigRequest $request, ProtocolConfigService $protocol): BlockchainProtocolConfigResource
    {
        return new BlockchainProtocolConfigResource($protocol->confirmInitialization($request->validated()));
    }
}
