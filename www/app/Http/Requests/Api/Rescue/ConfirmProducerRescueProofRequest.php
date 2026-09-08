<?php

namespace App\Http\Requests\Api\Rescue;

use App\Http\Requests\Api\ApiRequest;
use App\Rules\SolanaTransactionSignature;

class ConfirmProducerRescueProofRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'signature' => ['required', 'string', new SolanaTransactionSignature],
        ];
    }
}
