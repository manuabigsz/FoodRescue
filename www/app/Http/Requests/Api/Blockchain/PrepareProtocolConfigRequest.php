<?php

namespace App\Http\Requests\Api\Blockchain;

use App\Http\Requests\Api\ApiRequest;
use App\Rules\SolanaAddress;

class PrepareProtocolConfigRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'config_pda' => ['required', new SolanaAddress],
        ];
    }
}
