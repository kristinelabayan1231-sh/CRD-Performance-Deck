<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An FSA Pancake account whose customers belong to an exclusive CRA ($user_id) while no CRA has sold to them
 * (ExclusiveFsaCustomers). $seller_name is the account's name as Pancake orders carry it.
 */
#[Fillable(['user_id', 'seller_name', 'set_by'])]
class ExclusiveFsaAccount extends Model
{
    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function setter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'set_by');
    }
}
