<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProductStageController extends Controller
{
    /**
     * Every product as Testing or Existing.
     */
    public function index(): View
    {
        return view('settings.product-stage', [
            'products' => Product::crd()->orderBy('name')->get(['id', 'name', 'is_testing']),
        ]);
    }

    /**
     * Save which products are testing: the ones ticked; every other product is existing.
     */
    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'testing' => ['nullable', 'array'],
            'testing.*' => ['integer'],
        ]);
        $testing = array_map('intval', $data['testing'] ?? []);

        Product::crd()->get()->each(fn (Product $product) => $product->update(['is_testing' => in_array($product->id, $testing, true)]));

        return back()->with('status', 'Testing products saved.');
    }
}
