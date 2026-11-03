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
     * Each item's cost on $day: the one with the latest effective_from on or before it. A day before an item's first
     * cost takes that first cost (Shecom dated every cost from when it was entered, Nov 3 2026).
     *
     * @return array<string, float> lowercased item name => cost
     */
    public static function costsOn(string $day): array
    {
        $costs = [];

        static::query()
            ->orderByRaw('effective_from is null desc')->orderBy('effective_from')
            ->get(['product', 'cost', 'effective_from'])
            ->each(function (self $row) use (&$costs, $day) {
                $product = mb_strtolower($row->product);
                // Oldest first: the first cost seeds the item, a later one replaces it from its day.
                if (! isset($costs[$product]) || $row->effective_from === null || $row->effective_from->toDateString() <= $day) {
                    $costs[$product] = (float) $row->cost;
                }
            });

        return $costs;
    }
}
