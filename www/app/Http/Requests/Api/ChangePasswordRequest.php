<?php

namespace App\Http\Requests\Api;

use App\Support\PasswordRules;

class ChangePasswordRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string', 'max:72'],
            'password' => [...PasswordRules::rules(), 'different:current_password'],
            'password_confirmation' => ['required', 'string', 'min:6', 'max:72'],
        ];
    }
}
