<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A lead the backlog fill (BacklogFill) moved off a CRA's lead day while untouched. Day totals add these
 * back as listed but not catered, so a day's total stays what the CRA was given that day.
 */
#[Fillable(['lead_id', 'assigned_to', 'listed_on', 'moved_to'])]
class LeadMove extends Model
{
    protected function casts(): array
    {
        return [
            'listed_on' => 'immutable_date',
            'moved_to' => 'immutable_date',
        ];
    }

    /**
     * Moves off lead days $from–$to, joined to their lead so lead filters (type, product) work; optionally
     * only these CRAs, the lead type and the product.
     *
     * @param  iterable<int>|null  $craIds
     */
    public static function departed(CarbonImmutable $from, CarbonImmutable $to, ?iterable $craIds = null, ?string $type = null, ?string $product = null): Builder
    {
        return self::query()
            ->join('leads', 'leads.id', '=', 'lead_moves.lead_id')
            ->whereDate('lead_moves.listed_on', '>=', $from)->whereDate('lead_moves.listed_on', '<=', $to)
            ->when($craIds !== null, fn (Builder $q) => $q->whereIn('lead_moves.assigned_to', collect($craIds)->all()))
            ->when($type, fn (Builder $q) => $q->where('leads.lead_type', $type))
            ->when($product, fn (Builder $q) => $q->where('leads.product_name', $product));
    }

    /**
     * Moved-off leads per CRA and lead day: [cra id][Y-m-d] => count.
     *
     * @return array<int, array<string, int>>
     */
    public static function countsByCraAndDay(Builder $departed): array
    {
        $counts = [];

        (clone $departed)->selectRaw('lead_moves.assigned_to as cra, date(lead_moves.listed_on) as day, count(*) as n')
            ->groupBy('cra', 'day')->toBase()->get()
            ->each(function ($row) use (&$counts) {
                $counts[(int) $row->cra][CarbonImmutable::parse($row->day)->toDateString()] = (int) $row->n;
            });

        return $counts;
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }
}
