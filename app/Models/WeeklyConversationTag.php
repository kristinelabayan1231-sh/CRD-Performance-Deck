<?php

namespace App\Models;

use App\Support\WeekBlocks;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Pancake's "Conversation Tag Exclude" filter: CRD creates a brand-new tag
 * every 7-day block and applies it to conversations that should be excluded
 * from that week's Total Inquiries count (not an include filter). week_start
 * is stored/compared as a plain Y-m-d string, matching the other weekly-block
 * models, to avoid the Carbon-cast unique-constraint bug hit previously on
 * pc_day_stats/cra_pc_day_stats.
 */
class WeeklyConversationTag extends Model
{
    protected $fillable = ['week_start', 'tag_name'];

    public static function forWeek(Carbon $date): ?self
    {
        return static::where('week_start', WeekBlocks::startOf($date)->toDateString())->first();
    }
}
