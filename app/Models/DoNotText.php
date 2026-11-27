<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A number that gets no restock texts: the customer replied STOP, or a CRA left them out because they asked not to be
 * texted. Ticking them again (RestockReminders::allowAgain) takes the number off.
 */
#[Fillable(['phone_key', 'reason', 'added_by'])]
class DoNotText extends Model
{
    public static function has(string $phoneKey): bool
    {
        return static::where('phone_key', $phoneKey)->exists();
    }
}
