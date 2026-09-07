<?php

namespace App\Http\Requests\Api;

class CancelTradeRequest extends ApiRequest
{
    public function rules(): array
    {
        return ['reason' => ['nullable', 'string', 'max:500']];
    }
}
