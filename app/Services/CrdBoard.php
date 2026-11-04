<?php

namespace App\Services;

use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * CRD Board (dashboard): the department's figures from the Daily I.S., every active CRA. The day's Gross Sales and Net
 * Income (today: running so far), the same for the two days before, and the same day's top seller and top product: the
 * CRA and the product card with the highest Net Income among those with sales. The top product shows its photo from the
 * Drive "Product Photos" folder when it has one.
 */
class CrdBoard
{
    public function __construct(private DailyIncomeStatement $statement, private ProductPhotos $photos) {}

    /**
     * @return array{today: array{day: CarbonImmutable, gross: float, net: float}, days: list<array{day: CarbonImmutable, gross: float, net: float}>, topSeller: ?array{user: User, gross: float, net: float}, topProduct: ?array{name: string, image: ?string, gross: float, net: float}, topDay: CarbonImmutable}
     */
    public function for(CarbonImmutable $today): array
    {
        $cras = LeadGenerator::cras();
        $figures = fn (array $total) => ['gross' => (float) $total['gross'], 'net' => (float) $total['net_income']];
        $statements = collect([2, 1, 0])->mapWithKeys(fn (int $back) => [$back => $this->statement->day($cras, $today->subDays($back))]);
        $shown = $statements[0];

        $topSeller = collect($shown['cras'])
            ->map(fn (array $statement, int $craId) => ['user' => $cras->firstWhere('id', $craId), ...$figures($statement['total'])])
            ->filter(fn (array $row) => $row['gross'] > 0)
            ->sortByDesc('net')->first();

        $names = array_column($shown['products'], 'name', 'slug');
        $topProduct = collect($shown['department']['products'])
            ->map(fn (array $statement, string $slug) => ['name' => $names[$slug], 'image' => $this->photos->urlFor($names[$slug]), ...$figures($statement)])
            ->filter(fn (array $row) => $row['gross'] > 0)
            ->sortByDesc('net')->first();

        return [
            'today' => ['day' => $today, ...$figures($statements[0]['department']['total'])],
            'days' => array_map(fn (int $back) => ['day' => $today->subDays($back), ...$figures($statements[$back]['department']['total'])], [2, 1]),
            'topSeller' => $topSeller,
            'topProduct' => $topProduct,
            'topDay' => $today,
        ];
    }
}
