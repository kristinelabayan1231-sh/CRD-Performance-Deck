<?php

namespace App\Support;

use App\Models\Niche;
use App\Models\User;

/**
 * Settings → Niche: which CRAs may get a lead of which product. A CRA in a niche gets only its products' leads;
 * a CRA in none gets any product. With no niche saved every CRA gets every product, as before niches. A CRA's own
 * High AOV / VIP customers are not checked here: their leads always go to that CRA (LeadGenerator::ownerFor()).
 */
class NicheRules
{
    /**
     * @param  array<int, list<string>>  $productsOf  CRA id => the niche's product names (lower case)
     */
    public function __construct(private array $productsOf = []) {}

    public static function current(): self
    {
        $productsOf = [];

        Niche::with(['products:id,name', 'cras:id,niche_id'])->get()->each(function (Niche $niche) use (&$productsOf) {
            $names = $niche->products->map(fn ($product) => self::key($product->name))->all();
            $niche->cras->each(function (User $cra) use (&$productsOf, $names) {
                $productsOf[$cra->id] = $names;
            });
        });

        return new self($productsOf);
    }

    /**
     * Whether any CRA is in a niche.
     */
    public function active(): bool
    {
        return $this->productsOf !== [];
    }

    /**
     * Whether $craId may get a lead of $product (the lead's product name).
     */
    public function allows(int $craId, ?string $product): bool
    {
        return ! isset($this->productsOf[$craId]) || in_array(self::key((string) $product), $this->productsOf[$craId], true);
    }

    /**
     * The CRAs in a niche.
     *
     * @return list<int>
     */
    public function craIds(): array
    {
        return array_keys($this->productsOf);
    }

    private static function key(string $product): string
    {
        return mb_strtolower(trim($product));
    }
}
