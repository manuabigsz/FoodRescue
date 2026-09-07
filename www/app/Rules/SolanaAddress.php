<?php

namespace App\Rules;

use App\Support\Base58;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Throwable;

class SolanaAddress implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || strlen($value) < 32 || strlen($value) > 44) {
            $fail('O campo :attribute deve ser uma public key Solana válida.');

            return;
        }

        try {
            $decoded = Base58::decode($value);
        } catch (Throwable) {
            $fail('O campo :attribute deve ser uma public key Solana válida.');

            return;
        }

        if (strlen($decoded) !== 32) {
            $fail('O campo :attribute deve ser uma public key Solana válida.');
        }
    }
}
