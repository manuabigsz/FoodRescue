<?php

namespace App\Http\Requests\Api\Surplus;

use App\Enums\LogisticsMode;
use App\Enums\SurplusUnit;
use App\Http\Requests\Api\ApiRequest;
use Illuminate\Validation\Rule;

class StoreSurplusRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'agricultural_product_id' => ['required', 'integer', Rule::exists('agricultural_products', 'id')->where('active', true)],
            'quality_grade_id' => ['nullable', 'integer', Rule::exists('quality_grades', 'id')->where('active', true)],
            'quantity' => ['required', 'numeric', 'gt:0', 'max:99999999999.999', 'decimal:0,3'],
            'unit' => ['required', Rule::enum(SurplusUnit::class)],
            'origin_address' => ['required', 'string', 'max:255'],
            'origin_city' => ['required', 'string', 'max:120'],
            'origin_state' => ['required', 'string', 'max:80'],
            'origin_country' => ['required', 'string', 'size:2'],
            'harvest_date' => ['required', 'date'],
            'available_until' => ['required', 'date', 'after:now'],
            'asking_price' => ['required', 'numeric', 'gte:0', 'max:999999999999.999999', 'decimal:0,6'],
            'minimum_price' => ['nullable', 'numeric', 'gte:0', 'lte:asking_price', 'decimal:0,6', 'max:999999999999.999999'],
            'donation_eligible' => ['required', 'boolean'],
            'accepted_logistics_modes' => ['required', 'array', 'min:1'],
            'accepted_logistics_modes.*' => ['required', Rule::in(LogisticsMode::supportedValues()), 'distinct'],
        ];
    }
}
