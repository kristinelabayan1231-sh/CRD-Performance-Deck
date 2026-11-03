<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Shecom's cost of one Pancake item (its name as on the order, e.g. "2 Bottles Sinuxyl") from effective_from on
 * (null: from the start), but never before the day it was saved. Each cost Shecom has sent is kept, so a change only
 * counts from its day.
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
     * Each item's cost on $day: the one with the latest start on or before it. A cost starts on its effective_from or
     * on the day the app first saved it, whichever is later, so a day that had no cost (₱0) keeps it when a cost comes
     * later, even one Shecom dates back.
     *
     * @return array<string, float> lowercased item name => cost
     */
    public static function costsOn(string $day): array
    {
        $timezone = config('segmentation.timezone');

        return static::query()->get(['product', 'cost', 'effective_from', 'created_at'])
            ->map(fn (self $row) => [
                'product' => mb_strtolower($row->product),
                'cost' => (float) $row->cost,
                'from' => max((string) $row->effective_from?->toDateString(), $row->created_at->timezone($timezone)->toDateString()),
            ])
            ->filter(fn (array $row) => $row['from'] <= $day)
            ->sortBy('from')
            ->mapWithKeys(fn (array $row) => [$row['product'] => $row['cost']])
            ->all();
    }
}
