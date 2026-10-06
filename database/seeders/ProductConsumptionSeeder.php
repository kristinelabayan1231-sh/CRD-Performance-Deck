<?php

namespace Database\Seeders;

use App\Models\Product;
use Illuminate\Database\Seeder;

/**
 * Settings → Product Consumption: the products and how many days one unit
 * lasts (matching the retention API). Safe to run again: adds missing
 * products and sets their days; keywords already entered are kept.
 *
 * php artisan db:seed --class=ProductConsumptionSeeder --force
 */
class ProductConsumptionSeeder extends Seeder
{
    /** @var array<string, int> product => consumption days per unit */
    public const PRODUCTS = [
        'AudiCure' => 15,
        'CanPro' => 10,
        'Clearsight' => 15,
        'Ginseng' => 15,
        'NutriLay' => 15,
        'Pterygium' => 15,
        'Pterylief' => 15,
        'Scar Cream' => 10,
        'Sinuxyl' => 15,
    ];

    public function run(): void
    {
        foreach (self::PRODUCTS as $name => $days) {
            Product::updateOrCreate(['name' => $name], ['consumption_days' => $days]);
        }
    }
}
