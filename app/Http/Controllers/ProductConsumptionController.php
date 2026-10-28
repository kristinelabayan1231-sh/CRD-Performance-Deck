<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Services\CustomerDatabase;
use App\Services\ProductCatalog;
use App\Support\WorkingDate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

use function Illuminate\Support\defer;

class ProductConsumptionController extends Controller
{
    /**
     * One list of CRD and TSD upsell products (filtered by ?source), with the product picked (?product) or the add
     * form (?add=crd|tsd) in a panel beside it.
     */
    public function index(Request $request): View
    {
        $products = Product::orderBy('name')->get();
        $source = in_array($request->query('source'), ['crd', 'tsd'], true) ? $request->query('source') : null;
        $add = in_array($request->query('add'), ['crd', 'tsd'], true) ? $request->query('add') : null;

        // Only those who may add that kind of product get its add form.
        if ($add !== null && ! $this->canChange($request, $add === 'tsd')) {
            $add = null;
        }

        return view('settings.product-consumption', [
            'products' => $source === null ? $products : $products->where('is_tsd', $source === 'tsd')->values(),
            'counts' => ['all' => $products->count(), 'crd' => $products->where('is_tsd', false)->count(), 'tsd' => $products->where('is_tsd', true)->count()],
            'source' => $source,
            'add' => $add,
            'selected' => $add === null ? $products->firstWhere('id', (int) $request->query('product')) : null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $isTsd = $request->boolean('is_tsd');
        abort_unless($this->canChange($request, $isTsd), 403);

        if ($isTsd && $request->has('products')) {
            return $this->storeTsdProducts($request);
        }

        $data = $this->validated($request);
        // Testing or existing: decided when a CRD product is added (Settings → Testing Products changes it later).
        $data = [...$data, ...($isTsd
            ? ['is_tsd' => true, 'is_testing' => false, 'consumption_days' => null]
            : $request->validate(['is_testing' => ['required', 'boolean']], ['is_testing.required' => 'Choose whether the product is Testing or Existing.']))];

        // Counts from its starting date (today when none is given): orders, deliveries and leads before it are left as they were.
        Product::create([...$data, 'counts_from' => $data['counts_from'] ?? WorkingDate::realToday()->toDateString(), 'created_by' => $request->user()->id]);
        $catalog = app(ProductCatalog::class);
        $catalog->renormalizeLeads();
        $this->applyList($catalog, removeLeads: false);

        return redirect()->route('settings.product-consumption.index', ['source' => $request->query('source'), 'product' => Product::firstWhere('name', $data['name'])->id])
            ->with('status', ($isTsd ? 'TSD upsell product' : 'Product')." \"{$data['name']}\" added.");
    }

    /**
     * Several TSD upsell products from one submission (the add panel's product rows), all from one starting date.
     * Rows left blank are skipped.
     */
    private function storeTsdProducts(Request $request): RedirectResponse
    {
        $rows = collect($request->input('products'))
            ->filter(fn ($row) => is_array($row) && collect($row)->filter(fn ($field) => filled($field))->isNotEmpty())
            ->map(fn (array $row) => [
                'name' => trim((string) ($row['name'] ?? '')),
                'keywords' => $this->keywordList($row['keywords'] ?? null),
                'exclude_keywords' => $this->keywordList($row['exclude_keywords'] ?? null),
                'srp' => filled($row['srp'] ?? null) ? $row['srp'] : null,
                'unit_cost' => filled($row['unit_cost'] ?? null) ? $row['unit_cost'] : null,
            ])
            ->values();
        $request->merge(['products' => $rows->all(), 'counts_from' => filled($request->input('counts_from')) ? $request->input('counts_from') : null]);

        $validated = $request->validate([
            'products' => ['required', 'array', 'max:50'],
            'products.*.name' => ['required', 'string', 'max:255', 'distinct:ignore_case', Rule::unique('products', 'name')],
            'products.*.keywords' => ['nullable', 'string', 'max:1000'],
            'products.*.exclude_keywords' => ['nullable', 'string', 'max:1000'],
            'products.*.srp' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'products.*.unit_cost' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'counts_from' => ['nullable', 'date_format:Y-m-d'],
        ], [
            'products.required' => 'Enter at least one product.',
            'products.*.name.required' => 'Each product needs a name.',
            'products.*.name.unique' => 'A product named ":input" already exists.',
            'products.*.name.distinct' => '":input" is in the list twice.',
        ]);

        $countsFrom = $validated['counts_from'] ?? WorkingDate::realToday()->toDateString();
        $products = collect($validated['products'])->map(fn (array $row) => Product::create([
            ...$row, 'is_tsd' => true, 'is_testing' => false, 'consumption_days' => null, 'counts_from' => $countsFrom, 'created_by' => $request->user()->id,
        ]));
        $catalog = app(ProductCatalog::class);
        $catalog->renormalizeLeads();
        $this->applyList($catalog, removeLeads: false);

        return redirect()->route('settings.product-consumption.index', array_filter(['source' => $request->query('source'), 'product' => $products->count() === 1 ? $products->first()->id : null]))
            ->with('status', $products->count() === 1
                ? "TSD upsell product \"{$products->first()->name}\" added."
                : $products->count().' TSD upsell products added: '.$products->pluck('name')->join(', ').'.');
    }

    public function update(Request $request, Product $product): RedirectResponse
    {
        // Moving a product between CRD and TSD upsell (is_tsd sent) takes the full permission.
        $isTsd = $request->has('is_tsd') ? $request->boolean('is_tsd') : $product->is_tsd;
        abort_unless($this->canChange($request, $product->is_tsd && $isTsd), 403);

        $data = $this->validated($request, $product);
        $product->update([...$data, 'is_tsd' => $isTsd, ...($isTsd ? ['is_testing' => false, 'consumption_days' => null] : [])]);
        $catalog = app(ProductCatalog::class);
        $catalog->renormalizeLeads();
        $removed = $product->wasChanged(['name', 'keywords', 'exclude_keywords', 'counts_from', 'is_tsd']) ? $this->applyList($catalog) : 0;

        return back()->with('status', "Product \"{$product->name}\" updated.".$this->removedNote($removed));
    }

    /**
     * Whether the user may add, edit or delete a product: every product with product_consumption.manage, only TSD upsell
     * products with product_consumption.tsd.
     */
    private function canChange(Request $request, bool $tsd): bool
    {
        return $request->user()->can('product_consumption.manage') || ($tsd && $request->user()->can('product_consumption.tsd'));
    }

    /**
     * Only products on this list count anywhere in the deck. After it changes: drop the untouched
     * leads whose product is no longer on it, then re-tag the saved orders (left out of sales and
     * the Customer Database) after the page is sent, since that goes through every order and can
     * outlast a request's time limit.
     *
     * @return int leads removed
     */
    private function applyList(ProductCatalog $catalog, bool $removeLeads = true): int
    {
        $removed = $removeLeads ? $catalog->removeUntouchedUnlistedLeads() : 0;

        defer(function () {
            set_time_limit(0);
            app(ProductCatalog::class)->flagUnlistedOrders();
            CustomerDatabase::flushCache();
        });

        return $removed;
    }

    private function removedNote(int $removed): string
    {
        return $removed ? " Removed {$removed} untouched ".str('lead')->plural($removed).' whose product is no longer on the list.' : '';
    }

    /**
     * Only the SRP, for roles that may price products but not regroup them.
     */
    public function updateSrp(Request $request, Product $product): RedirectResponse
    {
        $request->merge(['srp' => filled($request->input('srp')) ? $request->input('srp') : null]);
        $data = $request->validateWithBag("product{$product->id}", [
            'srp' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
        ]);

        $product->update($data);

        return back()->with('status', "SRP of \"{$product->name}\" updated.");
    }

    public function destroy(Request $request, Product $product): RedirectResponse
    {
        abort_unless($this->canChange($request, $product->is_tsd), 403);

        $product->delete();
        $catalog = app(ProductCatalog::class);
        $catalog->renormalizeLeads();
        $removed = $this->applyList($catalog);

        return redirect()->route('settings.product-consumption.index', ['source' => $request->query('source')])
            ->with('status', "Product \"{$product->name}\" deleted.".$this->removedNote($removed));
    }

    /**
     * @return array{name: string, keywords: ?string, consumption_days: ?int, srp: ?string, unit_cost: ?string, counts_from?: ?string}
     */
    private function validated(Request $request, ?Product $product = null): array
    {
        $request->merge([
            'name' => trim((string) $request->input('name')),
            'keywords' => $this->keywordList($request->input('keywords')),
            'exclude_keywords' => $this->keywordList($request->input('exclude_keywords')),
            'consumption_days' => filled($request->input('consumption_days')) ? $request->input('consumption_days') : null,
            'srp' => filled($request->input('srp')) ? $request->input('srp') : null,
            'unit_cost' => filled($request->input('unit_cost')) ? $request->input('unit_cost') : null,
            // Blank = from the start; left out of the form = unchanged.
            ...($request->has('counts_from') ? ['counts_from' => filled($request->input('counts_from')) ? $request->input('counts_from') : null] : []),
        ]);

        // Edits are validated in their own error bag so they don't show on the add form.
        $bag = $product ? "product{$product->id}" : 'default';

        return $request->validateWithBag($bag, [
            'name' => ['required', 'string', 'max:255', Rule::unique('products', 'name')->ignore($product)],
            'keywords' => ['nullable', 'string', 'max:1000'],
            'exclude_keywords' => ['nullable', 'string', 'max:1000'],
            'consumption_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'srp' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'unit_cost' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'counts_from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ], [
            'name.unique' => 'A product with this name already exists.',
        ]);
    }

    /**
     * "a, b,, a" as "a, b"; nothing as null.
     */
    private function keywordList(mixed $keywords): ?string
    {
        return collect(explode(',', (string) $keywords))->map(fn ($k) => trim($k))->filter()->unique()->join(', ') ?: null;
    }
}
