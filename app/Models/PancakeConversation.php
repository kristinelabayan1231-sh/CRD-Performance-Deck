<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A Pancake chat conversation with a phone number: when it was created, its numbers
 * (last 10 digits) and its conversation tags (upper case), for PC Report's Total Inquiries.
 */
#[Fillable(['pancake_page_id', 'conversation_id', 'started_at', 'phone_keys', 'tags'])]
class PancakeConversation extends Model
{
    protected function casts(): array
    {
        return [
            'started_at' => 'immutable_datetime',
            'phone_keys' => 'array',
            'tags' => 'array',
        ];
    }
}
