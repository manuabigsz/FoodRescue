<?php

namespace App\Http\Requests\Api\Shipping;

use App\Http\Requests\Api\ApiRequest;

class CreateShippingRequestRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'destination_address' => ['required', 'string', 'max:255'],
            'destination_city' => ['required', 'string', 'max:120'],
            'destination_state' => ['required', 'string', 'max:80'],
            'destination_country' => ['required', 'string', 'size:2'],
        ];
    }
}
