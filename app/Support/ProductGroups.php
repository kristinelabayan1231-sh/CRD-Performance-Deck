<?php

namespace App\Support;

use App\Models\PancakePage;
use App\Models\Setting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Product groups (Settings → Pancake Pages): the Pancake pages that sell the same product. Each group is one set per
 * CRA in PC Report and one product card in the Daily I.S. Until groups are saved there, these sample groups stand in.
 *
 * A group remembers the day it was added (added_on, Manila day; null = there before days were kept), and on() leaves it
 * out of earlier days, so adding a product today doesn't change yesterday's PC Report or Daily I.S. (e.g. its per-product
 * shares of the CRA's costs); the same goes for a page added later. A group is known by its name: renaming one makes it
 * new from that day.
 */
class ProductGroups
{
    public const KEY = 'pancake_pages.product_groups';

    /** @var array<string, list<string>> */
    private const SAMPLE = [
        'Sinuxyl' => ['Sinuxyl Nasal Spray', 'Sinuxyl Nasal Care', 'Sinuxyl Official Page'],
        'ClearSight' => ['Clearsight Eye Drop', 'Clearsight Cataract', 'Clear Sight Essentials', 'Clear Sight Hub'],
    ];

    /**
     * @return list<array{slug: string, name: string, pages: list<string>, added_on: ?string}>
     */
    public static function all(): array
    {
        $saved = json_decode((string) Setting::value(self::KEY), true);
        $groups = is_array($saved) && $saved !== []
            ? $saved
            : collect(self::SAMPLE)->map(fn (array $pages, string $name) => ['name' => $name, 'pages' => $pages])->values()->all();

        return collect($groups)
            ->map(fn (array $group) => [
                'slug' => Str::slug($group['name']),
                'name' => $group['name'],
                'pages' => array_values($group['pages']),
                'added_on' => $group['added_on'] ?? null,
            ])
            ->values()
            ->all();
    }

    /**
     * The groups there on $day: every group added that day or before, with only its pages there that day (a page added
     * in Settings → Pancake Pages counts from its counts_from day).
     *
     * @return list<array{slug: string, name: string, pages: list<string>, added_on: ?string}>
     */
    public static function on(CarbonImmutable $day): array
    {
        $date = $day->toDateString();
        $later = PancakePage::whereNotNull('counts_from')->whereDate('counts_from', '>', $date)->pluck('name')
            ->map(fn (string $name) => Str::lower($name))->all();

        return array_values(array_map(
            fn (array $group) => [...$group, 'pages' => array_values(array_filter($group['pages'], fn (string $page) => ! in_array(Str::lower($page), $later, true)))],
            array_filter(self::all(), fn (array $group) => $group['added_on'] === null || $group['added_on'] <= $date),
        ));
    }

    /**
     * Whether groups were saved in Settings (otherwise the sample groups show).
     */
    public static function isSaved(): bool
    {
        return Setting::value(self::KEY) !== null;
    }

    /**
     * Save the groups. One already there (same name, any case) keeps its added day; a new one is added today. The first
     * save over the sample groups keeps them as there from the start.
     *
     * @param  list<array{name: string, pages: list<string>}>  $groups
     */
    public static function save(array $groups, ?User $by = null): void
    {
        $known = self::isSaved()
            ? collect(self::all())->mapWithKeys(fn (array $group) => [Str::lower($group['name']) => $group['added_on']])
            : null;
        $today = WorkingDate::realToday()->toDateString();

        $groups = array_map(fn (array $group) => [
            'name' => $group['name'],
            'pages' => array_values($group['pages']),
            'added_on' => $known === null ? null : ($known->has(Str::lower($group['name'])) ? $known[Str::lower($group['name'])] : $today),
        ], array_values($groups));

        Setting::put(self::KEY, json_encode($groups), $by);
    }
}
