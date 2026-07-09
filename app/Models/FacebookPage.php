<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FacebookPage extends Model
{
    protected $fillable = [
        'page_id',
        'page_name',
        'access_token',
    ];

    protected $casts = [
        'access_token' => 'encrypted',
    ];
}