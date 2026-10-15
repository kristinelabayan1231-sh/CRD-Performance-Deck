<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;

/**
 * PC Report: a CRA's page row with its Tagging and Creation/Update Time (the months its
 * conversations were created in). A split page has a DORMANT and an ACTIVE row instead.
 */
#[Fillable(['user_id', 'page', 'category', 'from_month', 'to_month', 'tagging', 'engagements', 'synced_at'])]
class PcReportRow extends Model
{
    public const DORMANT = 'DORMANT';

    public const ACTIVE = 'ACTIVE';

    protected function casts(): array
    {
        return [
            'from_month' => 'immutable_date',
            'to_month' => 'immutable_date',
            'engagements' => 'integer',
            'synced_at' => 'immutable_datetime',
        ];
    }

    /** A page counts as busy this long after its sync last reported a day (frees a page whose sync was cut off). */
    public const BUSY_SECONDS = 900;

    public static function pageBusyKey(int $pageId): string
    {
        return "pc-report.sync.page.{$pageId}";
    }

    /**
     * The row's Sync progress: status starting, running (done of total days), done or failed (message), or null.
     *
     * @return array{status: string, done?: int, total?: int, message?: string}|null
     */
    public function progress(): ?array
    {
        return Cache::get("pc-report.sync.row.{$this->id}");
    }

    /**
     * @param  array{status: string, done?: int, total?: int, message?: string}  $progress
     */
    public function putProgress(array $progress): void
    {
        Cache::put("pc-report.sync.row.{$this->id}", $progress, now()->addDay());
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
