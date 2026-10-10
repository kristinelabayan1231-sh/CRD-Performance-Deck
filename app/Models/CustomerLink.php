<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A contact number confirmed as the same customer as primary_phone_key (Customer Database merge).
 */
#[Fillable(['phone_key', 'primary_phone_key', 'linked_by'])]
class CustomerLink extends Model
{
    /**
     * The customer's main number for $phoneKey (itself when it isn't merged into another).
     */
    public static function primaryFor(string $phoneKey): string
    {
        return static::where('phone_key', $phoneKey)->value('primary_phone_key') ?? $phoneKey;
    }

    /**
     * Every number of the customer whose main number is $primary, main number first.
     *
     * @return list<string>
     */
    public static function membersOf(string $primary): array
    {
        return [$primary, ...static::where('primary_phone_key', $primary)->orderBy('id')->pluck('phone_key')->all()];
    }
}
