<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Credentials for Pancake's separate POS API (pos.pages.fm), used only to
 * look up order status ("Delivered") — distinct from the per-page
 * access_token used against the regular Pages/conversations API. One shop
 * covers every Facebook page on the account, so this is effectively a
 * singleton; `current()` returns the latest row.
 */
class PosCredential extends Model
{
    protected $fillable = ['shop_id', 'api_key'];

    protected $casts = [
        'api_key' => 'encrypted',
    ];

    public static function current(): ?self
    {
        return static::latest()->first();
    }
}
