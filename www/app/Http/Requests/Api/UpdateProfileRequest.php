<?php

namespace App\Http\Requests\Api;

use Illuminate\Validation\Rule;

class UpdateProfileRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'email' => ['sometimes', 'required', 'string', 'email:rfc', 'max:254',
                Rule::unique('users', 'email')->ignore($this->user()->id)],
            'current_password' => ['required_with:email', 'string', 'max:72'],
        ];
    }
}
