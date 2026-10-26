<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AgeBracketController extends Controller
{
    /**
     * Each product's target age bracket, to compare with the ages of the customers who bought it.
     */
    public function index(): View
    {
        return view('settings.age-bracket', [
            'products' => Product::orderBy('name')->get(),
        ]);
    }

    /**
     * Save every product's bracket at once. Either end may be blank (e.g. "45 and up").
     */
    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'brackets' => ['nullable', 'array'],
            'brackets.*.min' => ['nullable', 'integer', 'min:1', 'max:120'],
            'brackets.*.max' => ['nullable', 'integer', 'min:1', 'max:120'],
        ], [], ['brackets.*.min' => 'youngest age', 'brackets.*.max' => 'oldest age']);

        $products = Product::all();

        foreach ($products as $product) {
            $min = $data['brackets'][$product->id]['min'] ?? null;
            $max = $data['brackets'][$product->id]['max'] ?? null;
            if ($min !== null && $max !== null && (int) $min > (int) $max) {
                throw ValidationException::withMessages(["brackets.{$product->id}.min" => "{$product->name}: the youngest age can't be above the oldest."]);
            }
        }

        foreach ($products as $product) {
            $product->update([
                'age_min' => $data['brackets'][$product->id]['min'] ?? null,
                'age_max' => $data['brackets'][$product->id]['max'] ?? null,
            ]);
        }

        return back()->with('status', 'Age brackets saved.');
    }
}
