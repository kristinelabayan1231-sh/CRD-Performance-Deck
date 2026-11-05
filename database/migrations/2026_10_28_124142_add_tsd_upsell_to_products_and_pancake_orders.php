<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Settings → Product Consumption → TSD upsell: a TSD product CRAs may upsell, counted in their gross sales only.
            $table->boolean('is_tsd')->default(false)->after('is_testing');
        });

        Schema::table('pancake_orders', function (Blueprint $table) {
            // No CRD product but a TSD upsell product: counts in the CRA's sales, not in leads or the Customer Database.
            $table->boolean('tsd_upsell')->default(false)->after('non_crd');
        });
    }

    public function down(): void
    {
        Schema::table('pancake_orders', fn (Blueprint $table) => $table->dropColumn('tsd_upsell'));
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn('is_tsd'));
    }
};
