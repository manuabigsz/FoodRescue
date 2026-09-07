<?php

namespace App\Http\Requests\Api\Blockchain;

use App\Http\Requests\Api\ApiRequest;
use App\Rules\SolanaTransactionSignature;

class ConfirmCancellationRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'signature' => ['required', 'string', new SolanaTransactionSignature],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
