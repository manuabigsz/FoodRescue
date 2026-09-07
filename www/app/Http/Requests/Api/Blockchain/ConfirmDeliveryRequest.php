<?php

namespace App\Http\Requests\Api\Blockchain;

use App\Http\Requests\Api\ApiRequest;
use App\Rules\SolanaTransactionSignature;

class ConfirmDeliveryRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'signature' => ['nullable', new SolanaTransactionSignature],
        ];
    }
}
