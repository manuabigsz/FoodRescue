<?php

namespace App\Support;

use Closure;
use Illuminate\Validation\Rules\Password;

class PasswordRules
{
    /** @return array<int, mixed> */
    public static function rules(): array
    {
        return [
            'required', 'string', Password::min(6), 'max:72', 'confirmed',
            function (string $attribute, mixed $value, Closure $fail): void {
                if (is_string($value) && (strlen($value) > 72 || str_contains($value, "\0"))) {
                    $fail('A senha deve ter no máximo 72 bytes e não pode conter caracteres nulos.');
                }
            },
        ];
    }
}
