<?php

namespace App\Http\Requests\Api\Rescue;

use App\Http\Requests\Api\ApiRequest;
use App\Rules\SolanaAddress;
use App\Rules\SolanaTransactionSignature;

class ConfirmRescueProofRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'signature' => ['required', 'string', new SolanaTransactionSignature],
            'proof_pda' => ['required', 'string', new SolanaAddress],
        ];
    }
}
