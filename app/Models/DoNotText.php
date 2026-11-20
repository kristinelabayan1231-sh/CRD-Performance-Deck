<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A number that never gets a restock text again: the customer replied STOP, or a CRA left them out because they asked
 * not to be texted.
 */
#[Fillable(['phone_key', 'reason', 'added_by'])]
class DoNotText extends Model
{
    public static function has(string $phoneKey): bool
    {
        return static::where('phone_key', $phoneKey)->exists();
    }
}
