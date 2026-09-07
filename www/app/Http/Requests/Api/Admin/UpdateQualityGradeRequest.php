<?php

namespace App\Http\Requests\Api\Admin;

class UpdateQualityGradeRequest extends StoreQualityGradeRequest
{
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'sort_order' => ['sometimes', 'integer', 'between:0,65535'],
            'active' => ['sometimes', 'boolean'],
        ];
    }
}
