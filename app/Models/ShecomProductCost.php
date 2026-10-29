<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Shecom's cost of one Pancake item (its name as on the order, e.g. "2 Bottles Sinuxyl") from effective_from on
 * (null: from the start). Each cost Shecom has sent is kept, so a change only counts from its day.
 */
#[Fillable(['product', 'cost', 'effective_from'])]
class ShecomProductCost extends Model
{
    protected function casts(): array
    {
        return [
            'cost' => 'decimal:2',
            'effective_from' => 'immutable_date',
        ];
    }

    /**
     * Save Shecom's current costs; an item without a cost is skipped (it keeps any cost it had).
     *
     * @param  list<array{product?: ?string, currentCost?: int|float|string|null, currentEffectiveFrom?: ?string}>  $rows
     * @return int the items with a cost
     */
    public static function remember(array $rows): int
    {
        $timezone = config('segmentation.timezone');
        $saved = 0;

        foreach ($rows as $row) {
            $product = trim((string) ($row['product'] ?? ''));
            if ($product === '' || ! is_numeric($row['currentCost'] ?? null)) {
                continue;
            }

            $from = filled($row['currentEffectiveFrom'] ?? null)
                ? CarbonImmutable::parse($row['currentEffectiveFrom'], $timezone)->setTimezone($timezone)->toDateString()
                : null;
            static::updateOrCreate(['product' => $product, 'effective_from' => $from], ['cost' => (float) $row['currentCost']]);
            $saved++;
        }

        return $saved;
    }

    /**
     * Each item's cost on $day: the one with the latest effective_from on or before it.
     *
     * @return array<string, float> lowercased item name => cost
     */
    public static function costsOn(string $day): array
    {
        $costs = [];

        static::query()
            ->where(fn ($q) => $q->whereNull('effective_from')->orWhereDate('effective_from', '<=', $day))
            ->orderByRaw('effective_from is null desc')->orderBy('effective_from')
            ->get(['product', 'cost'])
            ->each(function (self $row) use (&$costs) {
                $costs[mb_strtolower($row->product)] = (float) $row->cost;
            });

        return $costs;
    }
}
