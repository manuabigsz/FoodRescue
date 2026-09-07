<?php

namespace App\Http\Requests\Api\Surplus;

use App\Enums\SurplusStatus;
use App\Http\Requests\Api\ApiRequest;
use Illuminate\Validation\Rule;

class ListSurplusRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'product_id' => ['sometimes', 'integer', 'exists:agricultural_products,id'],
            'quality_grade_id' => ['sometimes', 'integer', 'exists:quality_grades,id'],
            'city' => ['sometimes', 'string', 'max:120'],
            'state' => ['sometimes', 'string', 'max:80'],
            'min_price' => ['sometimes', 'numeric', 'gte:0'],
            'max_price' => ['sometimes', 'numeric', 'gte:0', ...($this->has('min_price') ? ['gte:min_price'] : [])],
            'status' => ['sometimes', Rule::enum(SurplusStatus::class)],
            'donation_eligible' => ['sometimes', 'boolean'],
            'sort' => ['sometimes', Rule::in(['urgency', 'price_asc', 'price_desc', 'newest'])],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
            'page' => ['sometimes', 'integer', 'between:1,10000'],
        ];
    }
}
