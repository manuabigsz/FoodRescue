<?php

namespace App\Http\Requests\Api\Blockchain;

use App\Http\Requests\Api\ApiRequest;
use App\Rules\SolanaAddress;
use App\Rules\SolanaTransactionSignature;

class ConfirmProtocolConfigRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'signature' => ['required', 'string', new SolanaTransactionSignature],
            'config_pda' => ['required', new SolanaAddress],
        ];
    }
}
