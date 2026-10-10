<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\LogisticsOrder;
use App\Models\PancakeOrder;
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

    /**
     * Whether every product named is a non-CRD product (Settings → Product Consumption: "Not a CRD
     * product"). An order with any other product, or a product not in Settings, is CRD's.
     *
     * @param  list<?string>  $names
     */
    public function onlyNonCrd(array $names): bool
    {
        $names = array_values(array_filter(array_map(fn (?string $name) => trim((string) $name), $names), fn (string $name) => $name !== ''));

        return $names !== [] && collect($names)->every(fn (string $name) => (bool) $this->match($name)?->not_crd);
    }

    /**
     * onlyNonCrd() for a product text that may list several products ("CanPro, NutriLay").
     */
    public function onlyNonCrdText(?string $products): bool
    {
        return $this->onlyNonCrd(preg_split('/\s*(?:,|\+|&|\/|\band\b)\s*/i', (string) $products) ?: []);
    }

    /**
     * Tag every saved order whose products are all non-CRD (and untag the rest), after the
     * products change. Logistics orders go by their product text, Pancake orders by their items.
     *
     * @return array{pancake: int, logistics: int} orders now tagged
     */
    public function flagNonCrdOrders(): array
    {
        $this->keywords = null;

        if (! Product::where('not_crd', true)->exists()) {
            PancakeOrder::where('non_crd', true)->update(['non_crd' => false]);
            LogisticsOrder::where('non_crd', true)->update(['non_crd' => false]);

            return ['pancake' => 0, 'logistics' => 0];
        }

        $products = LogisticsOrder::query()->distinct()->pluck('product')
            ->filter(fn (?string $product) => $this->onlyNonCrdText($product))->values();
        LogisticsOrder::where('non_crd', true)->whereNotIn('product', $products)->update(['non_crd' => false]);
        foreach ($products->chunk(500) as $chunk) {
            LogisticsOrder::whereIn('product', $chunk->all())->update(['non_crd' => true]);
        }

        $flagged = [];
        PancakeOrder::query()->select(['id', 'items'])->chunkById(5000, function ($orders) use (&$flagged) {
            foreach ($orders as $order) {
                if ($this->onlyNonCrd(array_column($order->items ?? [], 'name'))) {
                    $flagged[] = $order->id;
                }
            }
        });
        PancakeOrder::where('non_crd', true)->update(['non_crd' => false]);
        foreach (array_chunk($flagged, 1000) as $ids) {
            PancakeOrder::whereIn('id', $ids)->update(['non_crd' => true]);
        }

        return ['pancake' => count($flagged), 'logistics' => LogisticsOrder::where('non_crd', true)->count()];
    }

    /**
     * Remove Segmentation Tracker leads for non-CRD orders that nobody has worked on yet
     * (no status, tracking field, note, transfer or processing). Worked leads stay.
     *
     * @return int leads removed
     */
    public function removeUntouchedNonCrdLeads(): int
    {
        $this->keywords = null;

        $ids = Lead::query()
            ->whereNull('status')->whereNull('processed_at')->whereNull('notes')
            ->whereNull('repeat_purchase')->whereNull('customer_tag')->whereNull('feedback')
            ->whereNull('contact_date')->whereNull('contact_time')->whereNull('callback_date')
            ->whereDoesntHave('transfers')
            ->get(['id', 'product_raw', 'product_name'])
            ->filter(fn (Lead $lead) => $this->onlyNonCrdText($lead->product_raw ?? $lead->product_name))
            ->pluck('id');

        foreach ($ids->chunk(500) as $chunk) {
            Lead::whereIn('id', $chunk->all())->delete();
        }

        return $ids->count();
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
