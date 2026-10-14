<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Product groups: the Pancake pages that sell the same product. Each group is one set per CRA in PC Report.
 * Preview: these sample groups stand in until groups are saved from Settings → Pancake Pages.
 */
class ProductGroups
{
    /**
     * @return list<array{slug: string, name: string, pages: list<string>}>
     */
    public static function all(): array
    {
        $groups = [
            'Sinuxyl' => ['Sinuxyl Nasal Spray', 'Sinuxyl Nasal Care', 'Sinuxyl Official Page'],
            'ClearSight' => ['Clearsight Eye Drop', 'Clearsight Cataract', 'Clear Sight Essentials', 'Clear Sight Hub'],
        ];

        return collect($groups)
            ->map(fn (array $pages, string $name) => ['slug' => Str::slug($name), 'name' => $name, 'pages' => $pages])
            ->values()
            ->all();
    }
}
