<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Shecom's cost of one Pancake item (its name as on the order, e.g. "2 Bottles Sinuxyl") and the day Shecom says it took
 * effect. Each cost Shecom has sent is kept; the latest one is the item's cost, on every day.
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
     * Each item's current cost: the one with the latest effective_from (or, at the same date, the latest saved). The
     * Daily I.S. uses it for every day it takes Shecom's costs, so an added or changed cost shows on earlier days too.
     *
     * @return array<string, float> lowercased item name => cost
     */
    public static function current(): array
    {
        return static::query()
            ->orderByRaw('effective_from is null desc')->orderBy('effective_from')->orderBy('updated_at')->orderBy('id')
            ->get(['product', 'cost'])
            ->mapWithKeys(fn (self $row) => [mb_strtolower($row->product) => (float) $row->cost])
            ->all();
    }
}
