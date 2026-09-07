<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['key', 'value'])]
class PlatformSetting extends Model
{
    public const SHIPPING_QUOTATION_TIMEOUT = 'shipping_quotation_timeout_minutes';

    public const PAYMENT_TIMEOUT = 'payment_timeout_minutes';

    public static function integer(string $key, int $default): int
    {
        $value = static::query()->where('key', $key)->value('value');

        return is_numeric($value) ? (int) $value : $default;
    }
}
