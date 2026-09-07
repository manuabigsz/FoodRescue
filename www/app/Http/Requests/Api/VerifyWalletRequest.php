<?php

namespace App\Http\Requests\Api;

use App\Rules\SolanaSignature;

class VerifyWalletRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'challenge_id' => ['required', 'integer', 'min:1'],
            'signature' => ['required', new SolanaSignature],
        ];
    }
}
