<?php

namespace App\Http\Requests\Api\Admin;

use App\Http\Requests\Api\ApiRequest;

class UpdateTimeoutSettingsRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'shipping_quotation_timeout_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'payment_timeout_minutes' => ['required', 'integer', 'min:1', 'max:120'],
        ];
    }
}
