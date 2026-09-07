<?php

namespace App\Http\Requests\Api;

use App\Rules\SolanaAddress;

class WalletChallengeRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'wallet_address' => ['required', new SolanaAddress],
        ];
    }
}
