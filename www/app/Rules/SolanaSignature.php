<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class SolanaSignature implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('O campo :attribute deve ser uma assinatura Solana válida em Base64.');

            return;
        }

        $decoded = base64_decode($value, true);
        if (! is_string($decoded) || strlen($decoded) !== 64) {
            $fail('O campo :attribute deve ser uma assinatura Ed25519 válida em Base64.');
        }
    }
}
