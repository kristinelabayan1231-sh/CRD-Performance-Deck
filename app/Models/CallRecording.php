<?php

namespace App\Models;

use App\Services\LeadGenerator;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One call uploaded from a CRA's phone. The audio is in Google Drive (drive_file_id) and plays through the app.
 */
#[Fillable([
    'user_id', 'lead_id', 'phone_number', 'phone_key', 'direction', 'called_at', 'duration_seconds',
    'drive_file_id', 'mime_type', 'size_bytes', 'original_filename',
])]
class CallRecording extends Model
{
    public const DIRECTIONS = ['in' => 'Incoming', 'out' => 'Outgoing'];

    protected function casts(): array
    {
        return [
            'called_at' => 'datetime',
            'duration_seconds' => 'integer',
            'size_bytes' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    /**
     * The lead with this contact number: the uploading CRA's own lead first, then anyone's, latest lead day first.
     * Phone numbers are stored in mixed formats, so this matches on the last 10 digits as the tracker search does.
     */
    public static function matchLead(string $phoneKey, User $cra): ?Lead
    {
        if (strlen($phoneKey) < 10) {
            return null;
        }

        return Lead::where('phone_number', 'like', '%'.$phoneKey)
            ->orderByRaw('case when assigned_to = ? then 0 else 1 end', [$cra->id])
            ->latest('listed_on')
            ->first();
    }

    public static function phoneKey(string $phone): string
    {
        return LeadGenerator::normalizePhone($phone);
    }

    /**
     * 4:05, or 1:02:09 past an hour.
     */
    public static function formatDuration(?float $seconds): string
    {
        if ($seconds === null) {
            return '—';
        }

        $seconds = (int) round($seconds);

        return $seconds >= 3600
            ? sprintf('%d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60)
            : sprintf('%d:%02d', intdiv($seconds, 60), $seconds % 60);
    }
}
