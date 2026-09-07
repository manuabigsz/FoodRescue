<?php

namespace App\Http\Requests\Api\Admin;

class UpdateAgriculturalProductRequest extends StoreAgriculturalProductRequest
{
    public function rules(): array
    {
        return ['name' => ['sometimes', 'string', 'max:120'], 'active' => ['sometimes', 'boolean']];
    }
}
