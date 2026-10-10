<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Products that aren't the CRD team's (e.g. NutriLay), and orders whose products are all such
     * products: those orders are left out of leads, sales and the Customer Database.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('not_crd')->default(false)->after('srp');
        });

        Schema::table('pancake_orders', function (Blueprint $table) {
            $table->boolean('non_crd')->default(false)->after('items');
        });

        Schema::table('logistics_orders', function (Blueprint $table) {
            $table->boolean('non_crd')->default(false)->after('qty');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn('not_crd'));
        Schema::table('pancake_orders', fn (Blueprint $table) => $table->dropColumn('non_crd'));
        Schema::table('logistics_orders', fn (Blueprint $table) => $table->dropColumn('non_crd'));
    }
};
