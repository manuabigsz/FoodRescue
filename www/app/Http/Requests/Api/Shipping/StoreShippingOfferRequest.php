<?php

namespace App\Http\Requests\Api\Shipping;

use App\Http\Requests\Api\ApiRequest;

class StoreShippingOfferRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,6', 'max:999999999999.999999'],
            'pickup_at' => ['required', 'date', 'after:now'],
            'estimated_delivery_at' => ['required', 'date', 'after:pickup_at'],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ];
    }
}
