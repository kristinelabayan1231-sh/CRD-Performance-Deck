<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['email', 'is_admin'])]
class AllowedEmail extends Model
{
    protected function casts(): array
    {
        return [
            'is_admin' => 'boolean',
        ];
    }
}
