<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** The products still being tested (old and Pancake variation names); the rest are existing products. */
    private array $testing = [
        'Gallvex Herbal Drops', 'Gallvex Herbal Drops 2.0', 'Prime Man Herbal Tea', 'Cystora', 'Cystora 2.0',
        'Rose Soap', 'Rooster Medallion', 'Rooster Medallion 2.0',
    ];

    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Settings → Testing Products: a product still being tested, or an existing one.
            $table->boolean('is_testing')->default(false)->after('name');
        });

        DB::table('products')->whereIn('name', $this->testing)->update(['is_testing' => true]);
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('is_testing');
        });
    }
};
