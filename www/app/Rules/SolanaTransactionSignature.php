<?php

namespace App\Rules;

use App\Support\Base58;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Throwable;

class SolanaTransactionSignature implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        try {
            if (is_string($value) && strlen($value) <= 88 && strlen(Base58::decode($value)) === 64) {
                return;
            }
        } catch (Throwable) {
            // Invalid Base58 is a validation error, never a server error.
        }

        $fail('O campo :attribute deve ser uma assinatura de transação Solana em Base58.');
    }
}
