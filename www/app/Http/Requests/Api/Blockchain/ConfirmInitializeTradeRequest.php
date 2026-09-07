<?php

namespace App\Http\Requests\Api\Blockchain;

use App\Http\Requests\Api\ApiRequest;
use App\Rules\SolanaAddress;
use App\Rules\SolanaTransactionSignature;

class ConfirmInitializeTradeRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'signature' => ['required', 'string', new SolanaTransactionSignature],
            'trade_pda' => ['required', new SolanaAddress],
            'vault_token_account' => ['required', new SolanaAddress],
        ];
    }
}
