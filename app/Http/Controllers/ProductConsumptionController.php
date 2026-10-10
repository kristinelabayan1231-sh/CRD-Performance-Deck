<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Services\CustomerDatabase;
use App\Services\ProductCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

use function Illuminate\Support\defer;

class ProductConsumptionController extends Controller
{
    public function index(): View
    {
        return view('settings.product-consumption', [
            'products' => Product::orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        Product::create([...$data, 'created_by' => $request->user()->id]);
        $catalog = app(ProductCatalog::class);
        $catalog->renormalizeLeads();
        $this->applyList($catalog, removeLeads: false);

        return back()->with('status', "Product \"{$data['name']}\" added.");
    }

    public function update(Request $request, Product $product): RedirectResponse
    {
        $product->update($this->validated($request, $product));
        $catalog = app(ProductCatalog::class);
        $catalog->renormalizeLeads();
        $removed = $product->wasChanged(['name', 'keywords']) ? $this->applyList($catalog) : 0;

        return back()->with('status', "Product \"{$product->name}\" updated.".$this->removedNote($removed));
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

    public function destroy(Product $product): RedirectResponse
    {
        $product->delete();
        $catalog = app(ProductCatalog::class);
        $catalog->renormalizeLeads();
        $removed = $this->applyList($catalog);

        return back()->with('status', "Product \"{$product->name}\" deleted.".$this->removedNote($removed));
    }

    /**
     * @return array{name: string, keywords: ?string, consumption_days: ?int, srp: ?string}
     */
    private function validated(Request $request, ?Product $product = null): array
    {
        $keywords = collect(explode(',', (string) $request->input('keywords')))->map(fn ($k) => trim($k))->filter()->unique()->join(', ');
        $request->merge([
            'name' => trim((string) $request->input('name')),
            'keywords' => $keywords ?: null,
            'consumption_days' => filled($request->input('consumption_days')) ? $request->input('consumption_days') : null,
            'srp' => filled($request->input('srp')) ? $request->input('srp') : null,
        ]);

        // Edits are validated in their own error bag so they don't show on the add form.
        $bag = $product ? "product{$product->id}" : 'default';

        return $request->validateWithBag($bag, [
            'name' => ['required', 'string', 'max:255', Rule::unique('products', 'name')->ignore($product)],
            'keywords' => ['nullable', 'string', 'max:1000'],
            'consumption_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'srp' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
        ], [
            'name.unique' => 'A product with this name already exists.',
        ]);
    }
}
