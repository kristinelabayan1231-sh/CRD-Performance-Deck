<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A follow-up text a CRA typed and sent by hand from SMS Reminders. It goes out from the customer's CRA's phone
 * (phone_user_id), so replies come back to them; sent_by is who pressed Send (the CRA or a supervisor).
 */
#[Fillable(['restock_reminder_id', 'phone_user_id', 'sent_by', 'phone_number', 'body', 'sent_at'])]
class SmsMessage extends Model
{
    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
        ];
    }

    public function reminder(): BelongsTo
    {
        return $this->belongsTo(RestockReminder::class, 'restock_reminder_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }
}
