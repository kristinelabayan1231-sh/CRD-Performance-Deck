<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\Product;
use Illuminate\Support\Collection;

/**
 * Normalizes API product names to the products in Settings → Product Consumption.
 *
 * A product's name is its keyword ("Pterygium" catches "Pterygium Drops" and
 * "Pterygium Eye Drops"); extra keywords catch other spellings. Matching ignores
 * case, spaces and punctuation, so "Clearsight" also catches "Clear Sight 3.0".
 * The longest matching keyword wins.
 */
class ProductCatalog
{
    /** @var Collection<int, array{key: string, product: Product}>|null */
    private ?Collection $keywords = null;

    public function match(string $raw): ?Product
    {
        $name = self::squash($raw);

        if ($name === '') {
            return null;
        }

        return $this->keywords()
            ->first(fn (array $k) => str_contains($name, $k['key']))['product'] ?? null;
    }

    /**
     * Re-file every lead under its product after the product list changes.
     * Only the display name changes; dates and assignments stay as they are.
     */
    public function renormalizeLeads(): int
    {
        $this->keywords = null;
        $changed = 0;

        Lead::query()->select(['id', 'product_name', 'product_raw'])->chunkById(500, function ($leads) use (&$changed) {
            foreach ($leads as $lead) {
                $raw = $lead->product_raw ?? $lead->product_name;
                $name = $this->match($raw)?->name ?? $raw;

                if ($name !== $lead->product_name || $lead->product_raw === null) {
                    $lead->forceFill(['product_name' => $name, 'product_raw' => $raw])->saveQuietly();
                    $changed++;
                }
            }
        });

        return $changed;
    }

    public static function squash(string $value): string
    {
        return preg_replace('/[^a-z0-9]+/', '', mb_strtolower($value));
    }

    /**
     * Every keyword of every product, longest first.
     *
     * @return Collection<int, array{key: string, product: Product}>
     */
    private function keywords(): Collection
    {
        return $this->keywords ??= Product::all()
            ->flatMap(fn (Product $product) => collect([$product->name, ...$product->keywordList()])
                ->map(fn ($k) => ['key' => self::squash($k), 'product' => $product]))
            ->filter(fn ($k) => $k['key'] !== '')
            ->unique('key')
            ->sortByDesc(fn ($k) => strlen($k['key']))
            ->values();
    }
}
