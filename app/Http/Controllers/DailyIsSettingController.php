<?php

namespace App\Http\Controllers;

use App\Models\PancakeOrder;
use App\Models\Setting;
use App\Models\ShecomProductCost;
use App\Services\DailyIncomeStatement;
use App\Services\ProductCatalog;
use App\Support\ProductGroups;
use App\Support\WorkingDate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class DailyIsSettingController extends Controller
{
    /** How far back to look for sold items Shecom has no cost for. */
    private const MISSING_COST_DAYS = 14;

    /**
     * Every product group, in the Daily I.S. order, with whether it is deactivated; and Shecom's product costs (the
     * Daily I.S. Product Cost) with the items sold lately that Shecom has no cost for.
     */
    public function index(ProductCatalog $catalog): View
    {
        $today = WorkingDate::realToday();
        $deactivated = DailyIncomeStatement::deactivated();

        return view('settings.daily-is', [
            'products' => array_map(fn (array $group) => [
                'group' => $group['name'],
                'name' => DailyIncomeStatement::cardName($group['name']),
                'deactivated' => in_array(Str::lower($group['name']), $deactivated, true),
            ], DailyIncomeStatement::inProductOrder(ProductGroups::all())),
            'lastChange' => Setting::where('key', DailyIncomeStatement::DEACTIVATED_KEY)->with('editor')->first(),
            ...$this->shecomCosts($catalog, $today->toDateString()),
            'missingDays' => self::MISSING_COST_DAYS,
        ]);
    }

    /**
     * Each item's cost today (as the Daily I.S. reads it) with the product it counts as, and the items on Product
     * Consumption sold by the CRAs in the last MISSING_COST_DAYS days that Shecom has no cost for, with their pieces.
     *
     * @return array{costs: list<array{item: string, cost: float, from: ?string, product: ?string}>, missingCosts: list<array{item: string, product: string, pieces: int}>, costsUpdatedAt: ?string}
     */
    private function shecomCosts(ProductCatalog $catalog, string $today): array
    {
        $costs = ShecomProductCost::current();
        $from = ShecomProductCost::orderBy('effective_from')->get(['product', 'effective_from'])
            ->mapWithKeys(fn (ShecomProductCost $row) => [Str::lower($row->product) => $row->effective_from?->toDateString()]);
        $names = ShecomProductCost::pluck('product')->mapWithKeys(fn (string $name) => [Str::lower($name) => $name]);

        $missing = [];
        PancakeOrder::crdSales()->whereDate('ordered_on', '>=', now()->subDays(self::MISSING_COST_DAYS)->toDateString())
            ->whereNotNull('items')->pluck('items')
            ->each(function (array $items) use (&$missing, $costs, $catalog, $today) {
                foreach ($items as $item) {
                    $name = trim((string) ($item['name'] ?? ''));
                    $product = $name === '' || isset($costs[Str::lower($name)]) ? null : $catalog->matchSold($name, $today);
                    if ($product !== null) {
                        $missing[$name] ??= ['item' => $name, 'product' => $product->name, 'pieces' => 0];
                        $missing[$name]['pieces'] += max(1, (int) ($item['qty'] ?? 1)) * max(1, (int) ($item['pieces'] ?? 1));
                    }
                }
            });

        return [
            'costs' => collect($costs)->map(fn (float $cost, string $key) => [
                'item' => $names[$key] ?? $key, 'cost' => $cost, 'from' => $from[$key] ?? null, 'product' => $catalog->matchSold($names[$key] ?? $key, $today)?->name,
            ])->sortBy(fn (array $row) => [$row['product'] === null, Str::lower((string) $row['product']), Str::lower($row['item'])])->values()->all(),
            'missingCosts' => collect($missing)->sortByDesc('pieces')->values()->all(),
            'costsUpdatedAt' => ShecomProductCost::max('updated_at'),
        ];
    }

    /**
     * Save which products are deactivated: shown in the Daily I.S. only on a day they have sales, with a label.
     */
    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'deactivated' => ['nullable', 'array'],
            'deactivated.*' => ['string', Rule::in(array_column(ProductGroups::all(), 'name'))],
        ]);

        DailyIncomeStatement::saveDeactivated($data['deactivated'] ?? [], $request->user());

        return back()->with('status', 'Daily I.S. products saved.');
    }
}
