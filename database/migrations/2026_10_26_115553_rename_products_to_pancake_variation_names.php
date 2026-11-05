<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Products shown by the name of the Pancake variation selling now (old name => variation name). The old name
     * stays as a keyword, so every order, delivery and lead counts under the same product as before.
     */
    private array $names = [
        'CanPro' => 'CanPro Guyabano Herbal Drink',
        'Clearsight' => 'Clear Sight 3.0',
        'Ginseng' => 'GINSENG SERUM',
        'Pterylief' => 'Pterylief Eye Drops',
        'Scar Cream' => 'Scar Erase',
        'Gallvex Herbal Drops' => 'Gallvex Herbal Drops 2.0',
        'Rooster Medallion' => 'Rooster Medallion 2.0',
        'Cystora' => 'Cystora 2.0',
    ];

    public function up(): void
    {
        foreach ($this->names as $old => $new) {
            $this->rename($old, $new);
        }
    }

    public function down(): void
    {
        foreach ($this->names as $old => $new) {
            $this->rename($new, $old);
        }
    }

    /**
     * Rename one product, with $from moved into its keywords and $to taken out of them, and the leads and
     * reservations saved under its name.
     */
    private function rename(string $from, string $to): void
    {
        $product = DB::table('products')->where('name', $from)->first();

        if (! $product || DB::table('products')->where('name', $to)->exists()) {
            return;
        }

        $squash = fn (string $value) => preg_replace('/[^a-z0-9]+/', '', mb_strtolower($value));
        $keywords = collect([$from, ...explode(',', (string) $product->keywords)])
            ->map(fn (string $keyword) => trim($keyword))
            ->filter(fn (string $keyword) => $keyword !== '' && $squash($keyword) !== $squash($to))
            ->unique(fn (string $keyword) => $squash($keyword))
            ->join(', ');

        DB::table('products')->where('id', $product->id)->update(['name' => $to, 'keywords' => $keywords ?: null, 'updated_at' => now()]);
        DB::table('leads')->where('product_name', $from)->update(['product_name' => $to]);
        DB::table('pancake_reservations')->where('product_name', $from)->update(['product_name' => $to]);
    }
};
