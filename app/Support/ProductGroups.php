<?php

namespace App\Support;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Product groups (Settings → Pancake Pages): the Pancake pages that sell the same product. Each group is one set per
 * CRA in PC Report. Until groups are saved there, these sample groups stand in.
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
     * @return list<array{slug: string, name: string, pages: list<string>}>
     */
    public static function all(): array
    {
        $saved = json_decode((string) Setting::value(self::KEY), true);
        $groups = is_array($saved) && $saved !== []
            ? collect($saved)->mapWithKeys(fn (array $group) => [$group['name'] => $group['pages']])->all()
            : self::SAMPLE;

        return collect($groups)
            ->map(fn (array $pages, string $name) => ['slug' => Str::slug($name), 'name' => $name, 'pages' => array_values($pages)])
            ->values()
            ->all();
    }

    /**
     * Whether groups were saved in Settings (otherwise the sample groups show).
     */
    public static function isSaved(): bool
    {
        return Setting::value(self::KEY) !== null;
    }

    /**
     * @param  list<array{name: string, pages: list<string>}>  $groups
     */
    public static function save(array $groups, ?User $by = null): void
    {
        Setting::put(self::KEY, json_encode(array_values($groups)), $by);
    }
}
