<?php

namespace App\Models;

use Database\Factories\OrderStatusChangeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A Pancake order that left Canceled or Waiting for goods (e.g. recovered to Shipped). created_at is when the
 * sync noticed it, which is within minutes of the change for orders the sync looks at.
 */
#[Fillable(['pancake_order_id', 'from_status', 'to_status'])]
class OrderStatusChange extends Model
{
    /** @use HasFactory<OrderStatusChangeFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'from_status' => 'integer',
            'to_status' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<PancakeOrder, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(PancakeOrder::class, 'pancake_order_id', 'pancake_order_id');
    }

    /**
     * Record the orders that were Canceled or Waiting for goods and now have another status.
     *
     * @param  array<string, ?int>  $before  pancake_order_id => saved status
     * @param  array<string, ?int>  $after  pancake_order_id => Pancake's status now
     */
    public static function recordLeaving(array $before, array $after): void
    {
        $now = now();
        $rows = [];

        foreach ($after as $orderId => $status) {
            $was = $before[$orderId] ?? null;

            if (in_array($was, PancakeOrder::CANCELLED_OR_WAITING_STATUSES, true) && $status !== $was) {
                $rows[] = ['pancake_order_id' => (string) $orderId, 'from_status' => $was, 'to_status' => $status, 'created_at' => $now, 'updated_at' => $now];
            }
        }

        if ($rows !== []) {
            self::insert($rows);
        }
    }
}
