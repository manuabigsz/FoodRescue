<?php

namespace App\Http\Requests\Api\Offer;

use App\Http\Requests\Api\ApiRequest;

class StoreOfferRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'gt:0', 'max:999999999999.999999', 'decimal:0,6'],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ];
    }
}
