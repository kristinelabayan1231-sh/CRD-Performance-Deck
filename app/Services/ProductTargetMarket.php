<?php

namespace App\Services;

use App\Models\CustomerAge;
use App\Models\PancakeOrder;
use App\Models\Product;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Product Target Market: the ages of each product's customers against its target bracket (Settings → Age Bracket).
 *
 * Customers are the contact numbers on every Pancake POS order of a listed product created in the period, whoever
 * sold it (canceled and deleted orders left out), each counted once per product. Age is the one a CRA noted in the
 * customer's POS order note ("Age: 17"), carried to the day of their first order of the product in the period.
 * Customers with no noted age are counted but left out of the percentages.
 */
class ProductTargetMarket
{
    /** Age groups of the heatmap: [youngest, oldest], null = no limit. */
    public const BANDS = [[null, 24], [25, 34], [35, 44], [45, 54], [55, 64], [65, null]];

    /** Share of customers inside the target for On target, and for Partly. */
    public const ON_TARGET = 0.75;

    public const PARTLY = 0.5;

    public function __construct(private ProductCatalog $catalog) {}

    /**
     * @return array{customers: int, withAge: int, products: Collection<int, array{product: Product, customers: int, withAge: int, bands: list<int>, inTarget: ?int, younger: ?int, older: ?int, share: ?float, top: ?int, suggested: ?array{0: int, 1: int}, status: string}>}
     */
    public function build(CarbonImmutable $from, CarbonImmutable $to): array
    {
        // Product id => phone key => the day of the customer's first order of it in the period.
        $firstOrders = [];
        PancakeOrder::where('ordered_on', '>=', $from->toDateString())->where('ordered_on', '<', $to->addDay()->toDateString())
            ->where(fn ($q) => $q->whereNull('status')->orWhereNotIn('status', PancakeOrder::NOT_COUNTED_STATUSES))
            ->where('non_crd', false)->whereNotNull('phone_key')
            ->toBase()->select(['id', 'phone_key', 'items', 'ordered_on'])
            ->lazyById(2000, 'id')
            ->each(function (object $order) use (&$firstOrders) {
                $day = substr((string) $order->ordered_on, 0, 10);
                foreach (json_decode((string) $order->items, true) ?? [] as $item) {
                    $product = $this->catalog->match(trim((string) ($item['name'] ?? '')), $day);
                    if ($product !== null && (! isset($firstOrders[$product->id][$order->phone_key]) || $day < $firstOrders[$product->id][$order->phone_key])) {
                        $firstOrders[$product->id][$order->phone_key] = $day;
                    }
                }
            });

        $phoneKeys = collect($firstOrders)->flatMap(fn (array $customers) => array_keys($customers))->map(fn ($key) => (string) $key)->unique()->values();
        // Phone keys are all digits, so they're added with + (merging would renumber them).
        $ages = [];
        foreach ($phoneKeys->chunk(5000) as $chunk) {
            $ages += CustomerAge::whereIn('phone_key', $chunk->all())->get()->keyBy('phone_key')->all();
        }

        $products = Product::whereIn('id', array_keys($firstOrders))->get()
            ->map(fn (Product $product) => self::summary($product, $firstOrders[$product->id], $ages))
            ->sortByDesc('customers')
            ->values();

        return [
            'customers' => $phoneKeys->count(),
            'withAge' => $phoneKeys->filter(fn (string $key) => isset($ages[$key]))->count(),
            'products' => $products,
        ];
    }

    /**
     * @param  array<string, string>  $firstOrders  phone key => Y-m-d
     * @param  array<string, CustomerAge>  $ages  by phone key
     * @return array{product: Product, customers: int, withAge: int, bands: list<int>, inTarget: ?int, younger: ?int, older: ?int, share: ?float, top: ?int, suggested: ?array{0: int, 1: int}, status: string}
     */
    private static function summary(Product $product, array $firstOrders, array $ages): array
    {
        $hasTarget = $product->age_min !== null || $product->age_max !== null;
        $bands = array_fill(0, count(self::BANDS), 0);
        $inTarget = $younger = $older = 0;

        foreach ($firstOrders as $phoneKey => $day) {
            $noted = $ages[(string) $phoneKey] ?? null;
            if ($noted === null) {
                continue;
            }

            $age = $noted->ageOn(CarbonImmutable::parse($day));
            $bands[self::bandOf($age)]++;

            match (true) {
                $product->age_min !== null && $age < $product->age_min => $younger++,
                $product->age_max !== null && $age > $product->age_max => $older++,
                default => $inTarget++,
            };
        }

        $withAge = array_sum($bands);
        $share = $hasTarget && $withAge > 0 ? $inTarget / $withAge : null;

        return [
            'product' => $product,
            'customers' => count($firstOrders),
            'withAge' => $withAge,
            'bands' => $bands,
            'inTarget' => $hasTarget ? $inTarget : null,
            'younger' => $hasTarget ? $younger : null,
            'older' => $hasTarget ? $older : null,
            'share' => $share,
            'top' => $withAge > 0 ? array_search(max($bands), $bands, true) : null,
            'suggested' => self::suggested($bands),
            'status' => match (true) {
                $withAge === 0 => 'no_ages',
                ! $hasTarget => 'no_target',
                $share >= self::ON_TARGET => 'on',
                $share >= self::PARTLY => 'partly',
                default => 'off',
            },
        ];
    }

    private static function bandOf(int $age): int
    {
        foreach (self::BANDS as $i => [$min, $max]) {
            if (($min === null || $age >= $min) && ($max === null || $age <= $max)) {
                return $i;
            }
        }

        return 0;
    }

    /**
     * The fewest neighbouring age groups holding at least ON_TARGET of the customers with an age (the fuller one on a
     * tie), as [first band, last band]; null when no customer has an age.
     *
     * @param  list<int>  $bands
     * @return array{0: int, 1: int}|null
     */
    public static function suggested(array $bands): ?array
    {
        $total = array_sum($bands);
        if ($total === 0) {
            return null;
        }

        $best = null;
        foreach (array_keys($bands) as $first) {
            $sum = 0;
            for ($last = $first; $last < count($bands); $last++) {
                $sum += $bands[$last];
                if ($sum / $total >= self::ON_TARGET) {
                    if ($best === null || $last - $first < $best[1] - $best[0] || ($last - $first === $best[1] - $best[0] && $sum > $best[2])) {
                        $best = [$first, $last, $sum];
                    }
                    break;
                }
            }
        }

        return [$best[0], $best[1]];
    }

    /**
     * Whether an age group overlaps the product's target (the heatmap outlines these).
     */
    public static function bandInTarget(Product $product, int $band): bool
    {
        if ($product->age_min === null && $product->age_max === null) {
            return false;
        }

        [$min, $max] = self::BANDS[$band];

        return ($product->age_max === null || $min === null || $min <= $product->age_max)
            && ($product->age_min === null || $max === null || $max >= $product->age_min);
    }

    public static function bandLabel(int $band): string
    {
        [$min, $max] = self::BANDS[$band];

        return match (true) {
            $min === null => 'Under '.($max + 1),
            $max === null => $min.'+',
            default => "{$min}–{$max}",
        };
    }

    /**
     * A bracket as people read it: "45 and up", "Up to 34", "25–44", or "Not set".
     */
    public static function bracketLabel(?int $min, ?int $max): string
    {
        return match (true) {
            $min === null && $max === null => 'Not set',
            $max === null => "{$min} and up",
            $min === null => "Up to {$max}",
            default => "{$min}–{$max}",
        };
    }

    /**
     * @param  array{0: int, 1: int}  $bands  [first band, last band]
     */
    public static function bandsLabel(array $bands): string
    {
        return self::bracketLabel(self::BANDS[$bands[0]][0], self::BANDS[$bands[1]][1]);
    }
}
