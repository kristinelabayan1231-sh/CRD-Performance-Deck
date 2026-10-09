<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Services\ProductCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

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
        app(ProductCatalog::class)->renormalizeLeads();

        return back()->with('status', "Product \"{$data['name']}\" added.");
    }

    public function update(Request $request, Product $product): RedirectResponse
    {
        $product->update($this->validated($request, $product));
        app(ProductCatalog::class)->renormalizeLeads();

        return back()->with('status', "Product \"{$product->name}\" updated.");
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
        app(ProductCatalog::class)->renormalizeLeads();

        return back()->with('status', "Product \"{$product->name}\" deleted.");
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
