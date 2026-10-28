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
 * The longest matching keyword wins. A product's "not this product" words (exclude_keywords) stop a match: with
 * "Nasal Inhaler" on Sinuxyl, "Sinuxyl Nasal Inhaler" isn't Sinuxyl (nor on the list, unless another product matches it).
 *
 * A product added later counts from its counts_from day: given the day of an order, delivery or lead ($on, Y-m-d), the
 * list is the products there that day, so adding one leaves earlier days as they were. Without a day every product counts.
 *
 * TSD upsell products (is_tsd) are kept apart: match() and the "unlisted" checks see only the CRD products, so leads, the
 * Customer Database and churn are unchanged; matchSold() and tsdUpsell() also see the TSD ones, for the CRAs' sales.
 */
class ProductCatalog
{
    /** @var Collection<int, array{key: string, product: Product}>|null */
    private ?Collection $keywords = null;

    /** @var Collection<int, array{key: string, product: Product}>|null */
    private ?Collection $tsdKeywords = null;

    public function match(string $raw, ?string $on = null): ?Product
    {
        return $this->matchIn($this->keywords(), $raw, $on);
    }

    /**
     * The CRD product the item is, else the TSD upsell product: how the CRAs' sales file an item.
     */
    public function matchSold(string $raw, ?string $on = null): ?Product
    {
        return $this->match($raw, $on) ?? $this->matchIn($this->tsdKeywords(), $raw, $on);
    }

    /**
     * Whether an order with these items has no CRD product but a TSD upsell product on $on: it counts in the CRA's sales only.
     *
     * @param  list<?string>  $names
     */
    public function tsdUpsell(array $names, ?string $on = null): bool
    {
        $names = array_filter(array_map(fn (?string $name) => trim((string) $name), $names), fn (string $name) => $name !== '');

        return $this->unlisted(array_values($names), $on)
            && collect($names)->contains(fn (string $name) => $this->matchIn($this->tsdKeywords(), $name, $on) !== null);
    }

    /**
     * @param  Collection<int, array{key: string, product: Product}>  $keywords
     */
    private function matchIn(Collection $keywords, string $raw, ?string $on): ?Product
    {
        $name = self::squash($raw);

        if ($name === '') {
            return null;
        }

        return $keywords
            ->first(fn (array $k) => str_contains($name, $k['key']) && ! $this->excluded($name, $k['product']) && self::countsOn($k['product'], $on))['product'] ?? null;
    }

    /**
     * The first day any of $names matches a product: null = every day (a product there from the start), false = never.
     *
     * @param  list<?string>  $names
     */
    public function listedFrom(array $names): string|false|null
    {
        $from = false;
        foreach ($names as $name) {
            $squashed = self::squash((string) $name);
            if ($squashed === '') {
                continue;
            }

            foreach ($this->keywords() as $k) {
                if (! str_contains($squashed, $k['key']) || $this->excluded($squashed, $k['product'])) {
                    continue;
                }
                $day = $k['product']->counts_from?->toDateString();
                if ($day === null) {
                    return null;
                }
                $from = $from === false ? $day : min($from, $day);
            }
        }

        return $from;
    }

    /**
     * listedFrom() for a product text that may list several products ("CanPro, NutriLay").
     */
    public function listedFromText(?string $products): string|false|null
    {
        return $this->listedFrom(self::split($products));
    }

    /**
     * Whether $squashed has one of the product's "not this product" words.
     */
    private function excluded(string $squashed, Product $product): bool
    {
        foreach ($product->excludeList() as $word) {
            $word = self::squash($word);
            if ($word !== '' && str_contains($squashed, $word)) {
                return true;
            }
        }

        return false;
    }

    private static function countsOn(Product $product, ?string $on): bool
    {
        return $on === null || $product->counts_from === null || $product->counts_from->toDateString() <= $on;
    }

    /**
     * @return list<string>
     */
    private static function split(?string $products): array
    {
        return preg_split('/\s*(?:,|\+|&|\/|\band\b)\s*/i', (string) $products) ?: [];
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
     * Whether none of the products named is on the Product Consumption list, the one list of
     * products the deck counts: such an order is left out of leads, sales and the Customer
     * Database. An order with no product names, or before any product is listed, counts.
     *
     * @param  list<?string>  $names
     */
    public function unlisted(array $names, ?string $on = null): bool
    {
        $names = array_values(array_filter(array_map(fn (?string $name) => trim((string) $name), $names), fn (string $name) => $name !== ''));

        return $names !== [] && $this->keywords()->isNotEmpty() && collect($names)->every(fn (string $name) => $this->match($name, $on) === null);
    }

    /**
     * unlisted() for a product text that may list several products ("CanPro, NutriLay").
     */
    public function unlistedText(?string $products, ?string $on = null): bool
    {
        return $this->unlisted(self::split($products), $on);
    }

    /**
     * Tag every saved order with no product from the list on its day (and untag the rest), after the list changes.
     * Logistics orders go by their product text and delivered date, Pancake orders by their items and order date, so a
     * product added later doesn't bring back orders from before it counted.
     *
     * @return array{pancake: int, logistics: int} orders now tagged
     */
    public function flagUnlistedOrders(): array
    {
        $this->keywords = $this->tsdKeywords = null;

        // Logistics: per product text, never / every day / from a day (delivered before it = tagged).
        foreach (LogisticsOrder::query()->distinct()->pluck('product')->chunk(500) as $chunk) {
            foreach ($chunk as $product) {
                $from = $this->listedFromText($product);
                $rows = fn () => $product === null ? LogisticsOrder::whereNull('product') : LogisticsOrder::where('product', $product);
                if ($from === false) {
                    // Blank or empty text counts (unlisted() is false for no names).
                    $rows()->update(['non_crd' => $this->unlistedText($product)]);
                } elseif ($from === null) {
                    $rows()->update(['non_crd' => false]);
                } else {
                    $rows()->where('delivered_date', '<', $from)->update(['non_crd' => true]);
                    $rows()->where(fn ($q) => $q->whereNull('delivered_date')->orWhere('delivered_date', '>=', $from))->update(['non_crd' => false]);
                }
            }
        }

        $flagged = $upsells = [];
        PancakeOrder::query()->select(['id', 'items', 'ordered_on'])->chunkById(5000, function ($orders) use (&$flagged, &$upsells) {
            foreach ($orders as $order) {
                $names = array_column($order->items ?? [], 'name');
                $on = $order->ordered_on?->toDateString();
                if ($this->unlisted($names, $on)) {
                    $flagged[] = $order->id;
                    if ($this->tsdUpsell($names, $on)) {
                        $upsells[] = $order->id;
                    }
                }
            }
        });
        PancakeOrder::where('non_crd', true)->orWhere('tsd_upsell', true)->update(['non_crd' => false, 'tsd_upsell' => false]);
        foreach (array_chunk($flagged, 1000) as $ids) {
            PancakeOrder::whereIn('id', $ids)->update(['non_crd' => true]);
        }
        foreach (array_chunk($upsells, 1000) as $ids) {
            PancakeOrder::whereIn('id', $ids)->update(['tsd_upsell' => true]);
        }

        return ['pancake' => count($flagged), 'logistics' => LogisticsOrder::where('non_crd', true)->count()];
    }

    /**
     * Remove Segmentation Tracker leads whose product isn't on the list that nobody has worked on
     * yet (no status, tracking field, note, transfer or processing). Worked leads stay.
     *
     * @return int leads removed
     */
    public function removeUntouchedUnlistedLeads(): int
    {
        $this->keywords = null;

        $ids = Lead::query()
            ->whereNull('status')->whereNull('processed_at')->whereNull('notes')
            ->whereNull('repeat_purchase')->untagged()->whereNull('feedback')
            ->whereNull('contact_date')->whereNull('contact_time')->whereNull('callback_date')
            ->whereDoesntHave('transfers')
            ->get(['id', 'product_raw', 'product_name'])
            ->filter(fn (Lead $lead) => $this->unlistedText($lead->product_raw ?? $lead->product_name))
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
     * Every keyword of every CRD product, longest first.
     *
     * @return Collection<int, array{key: string, product: Product}>
     */
    private function keywords(): Collection
    {
        return $this->keywords ??= self::keywordsOf(Product::crd()->get());
    }

    /**
     * Every keyword of every TSD upsell product, longest first.
     *
     * @return Collection<int, array{key: string, product: Product}>
     */
    private function tsdKeywords(): Collection
    {
        return $this->tsdKeywords ??= self::keywordsOf(Product::where('is_tsd', true)->get());
    }

    /**
     * @param  Collection<int, Product>  $products
     * @return Collection<int, array{key: string, product: Product}>
     */
    private static function keywordsOf(Collection $products): Collection
    {
        return $products
            ->flatMap(fn (Product $product) => collect([$product->name, ...$product->keywordList()])
                ->map(fn ($k) => ['key' => self::squash($k), 'product' => $product]))
            ->filter(fn ($k) => $k['key'] !== '')
            ->unique('key')
            ->sortByDesc(fn ($k) => strlen($k['key']))
            ->values();
    }
}
