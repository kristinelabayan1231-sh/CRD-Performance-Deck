<?php

namespace Database\Seeders;

use App\Models\Product;
use Illuminate\Database\Seeder;

/**
 * Settings → Product Consumption: the products, named as their Pancake variation, and how many days one unit
 * lasts (matching the retention API). Safe to run again: adds missing products and sets their days; keywords
 * already entered are kept.
 *
 * php artisan db:seed --class=ProductConsumptionSeeder --force
 */
class ProductConsumptionSeeder extends Seeder
{
    /** @var array<string, int> product => consumption days per unit */
    public const PRODUCTS = [
        'AudiCure' => 15,
        'CanPro Guyabano Herbal Drink' => 10,
        'Clear Sight 3.0' => 15,
        'GINSENG SERUM' => 15,
        'NutriLay' => 15,
        'Pterygium' => 15,
        'Pterylief Eye Drops' => 15,
        'Scar Erase' => 10,
        'Sinuxyl' => 15,
    ];

    /** @var array<string, string> a new product's keywords: the shorter name leads and deliveries use */
    public const KEYWORDS = [
        'CanPro Guyabano Herbal Drink' => 'CanPro',
        'Clear Sight 3.0' => 'Clearsight',
        'GINSENG SERUM' => 'Ginseng',
        'Pterylief Eye Drops' => 'Pterylief',
        'Scar Erase' => 'Scar Cream',
    ];

    public function run(): void
    {
        foreach (self::PRODUCTS as $name => $days) {
            $product = Product::firstOrNew(['name' => $name]);
            if (! $product->exists) {
                $product->keywords = self::KEYWORDS[$name] ?? null;
            }
            $product->consumption_days = $days;
            $product->save();
        }
    }
}
