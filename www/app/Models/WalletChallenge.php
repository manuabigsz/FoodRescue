<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'wallet_address', 'purpose', 'nonce', 'message', 'expires_at', 'used_at'])]
class WalletChallenge extends Model
{
    protected $dateFormat = 'Y-m-d H:i:sP';

    public const PURPOSE_REGISTRATION = 'registration';

    public const PURPOSE_VERIFY = 'verify';

    public const PURPOSE_SURPLUS_PUBLICATION = 'surplus_publication';

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }
}
