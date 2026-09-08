<?php

namespace App\Http\Requests\Api\Trade;

use App\Enums\TradeStatus;
use App\Http\Requests\Api\ApiRequest;
use Illuminate\Validation\Rule;

class ListTradesRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'status' => ['sometimes', Rule::enum(TradeStatus::class)],
            'is_donation' => ['sometimes', 'boolean'],
            'active' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
            'page' => ['sometimes', 'integer', 'between:1,10000'],
        ];
    }
}
