<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A customer's age as a CRA noted it in Pancake POS ("Age: 17" in the order's note), by contact number, with the day
 * of that order. The newest note wins; an order without one never clears it.
 */
#[Fillable(['phone_key', 'age', 'noted_on'])]
class CustomerAge extends Model
{
    protected function casts(): array
    {
        return [
            'age' => 'integer',
            'noted_on' => 'immutable_date',
        ];
    }

    /**
     * The customer's age on $day: the noted age plus the whole years since (or minus those before) the note.
     */
    public function ageOn(CarbonImmutable $day): int
    {
        return max(0, $this->age + (int) $this->noted_on->diffInYears($day));
    }

    /**
     * Save these customers' noted ages, keeping a newer note already saved.
     *
     * @param  array<string, array{age: int, noted_on: string}>  $ages  phone key => age and the day of its order (Y-m-d)
     */
    public static function remember(array $ages): void
    {
        $now = now();

        foreach (array_chunk($ages, 500, true) as $chunk) {
            $saved = self::whereIn('phone_key', array_map('strval', array_keys($chunk)))->pluck('noted_on', 'phone_key');
            $rows = collect($chunk)
                ->filter(fn (array $age, string $phoneKey) => ! isset($saved[$phoneKey]) || $saved[$phoneKey]->toDateString() <= $age['noted_on'])
                ->map(fn (array $age, string $phoneKey) => ['phone_key' => (string) $phoneKey, 'age' => $age['age'], 'noted_on' => $age['noted_on'], 'created_at' => $now, 'updated_at' => $now])
                ->values()->all();

            if ($rows !== []) {
                self::upsert($rows, ['phone_key'], ['age', 'noted_on', 'updated_at']);
            }
        }
    }
}
