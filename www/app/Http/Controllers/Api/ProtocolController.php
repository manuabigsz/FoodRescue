<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\BlockchainProtocolConfigResource;
use App\Models\BlockchainProtocolConfig;

class ProtocolController extends Controller
{
    public function show(): BlockchainProtocolConfigResource
    {
        return new BlockchainProtocolConfigResource(
            BlockchainProtocolConfig::query()->where('cluster', config('services.solana.cluster'))
                ->where('program_id', config('services.solana.program_id'))
                ->firstOrFail()
        );
    }
}
