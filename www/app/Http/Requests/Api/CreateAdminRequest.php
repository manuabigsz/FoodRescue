<?php

namespace App\Http\Requests\Api;

use App\Models\User;
use App\Support\PasswordRules;
use Illuminate\Validation\Rule;

class CreateAdminRequest extends ApiRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('createAdmin', User::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email:rfc', 'max:254', Rule::unique('users', 'email')],
            'password' => PasswordRules::rules(),
            'password_confirmation' => ['required', 'string', 'max:72'],
            'current_password' => ['required', 'string', 'max:72'],
        ];
    }
}
