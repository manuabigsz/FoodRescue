<?php

namespace App\Http\Requests\Api;

use App\UserStatus;
use Illuminate\Validation\Rule;

class UpdateUserStatusRequest extends ApiRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('updateStatus', $this->route('user')) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['status' => ['required', Rule::enum(UserStatus::class)]];
    }
}
