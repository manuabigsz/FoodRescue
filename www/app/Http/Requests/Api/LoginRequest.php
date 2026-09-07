<?php

namespace App\Http\Requests\Api;

class LoginRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email:rfc', 'max:254'],
            'password' => ['required', 'string', 'max:72'],
            'device_name' => ['sometimes', 'required', 'string', 'max:80'],
        ];
    }
}
