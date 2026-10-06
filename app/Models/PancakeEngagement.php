<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * One Pancake staff account's customer engagements on one day, summed over every page.
 */
#[Fillable(['date', 'pancake_user_id', 'staff_name', 'engagements'])]
class PancakeEngagement extends Model
{
    protected function casts(): array
    {
        return [
            'date' => 'immutable_date',
            'engagements' => 'integer',
        ];
    }

    /**
     * Pancake staff names come with stray double spaces and mixed case
     * ("CRD ANNA  PACLIBARE 2"), so names are compared in this form.
     */
    public static function staffKey(?string $name): string
    {
        return strtoupper(trim(preg_replace('/\s+/', ' ', (string) $name)));
    }
}
