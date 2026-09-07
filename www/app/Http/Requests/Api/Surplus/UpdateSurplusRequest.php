<?php

namespace App\Http\Requests\Api\Surplus;

use App\Enums\LogisticsMode;
use App\Enums\SurplusUnit;
use App\Http\Requests\Api\ApiRequest;
use Illuminate\Validation\Rule;

class UpdateSurplusRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'agricultural_product_id' => ['sometimes', 'integer', Rule::exists('agricultural_products', 'id')->where('active', true)],
            'quality_grade_id' => ['sometimes', 'nullable', 'integer', Rule::exists('quality_grades', 'id')->where('active', true)],
            'quantity' => ['sometimes', 'numeric', 'gt:0', 'max:99999999999.999', 'decimal:0,3'],
            'unit' => ['sometimes', Rule::enum(SurplusUnit::class)],
            'origin_address' => ['sometimes', 'string', 'max:255'],
            'origin_city' => ['sometimes', 'string', 'max:120'],
            'origin_state' => ['sometimes', 'string', 'max:80'],
            'origin_country' => ['sometimes', 'string', 'size:2'],
            'harvest_date' => ['sometimes', 'date'],
            'available_until' => ['sometimes', 'date', 'after:now'],
            'asking_price' => ['sometimes', 'numeric', 'gte:0', 'max:999999999999.999999', 'decimal:0,6'],
            'minimum_price' => ['sometimes', 'nullable', 'numeric', 'gte:0', 'decimal:0,6', 'max:999999999999.999999'],
            'donation_eligible' => ['sometimes', 'boolean'],
            'accepted_logistics_modes' => ['sometimes', 'array', 'min:1'],
            'accepted_logistics_modes.*' => ['required_with:accepted_logistics_modes', Rule::in(LogisticsMode::supportedValues()), 'distinct'],
        ];
    }
}
