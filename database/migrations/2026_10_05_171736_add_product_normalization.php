<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Products double as name groups: an order whose product name contains a
     * product's name (or one of its extra keywords) is filed under that product.
     * Leads keep the original API name in product_raw.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->text('keywords')->nullable()->after('name');
        });

        Schema::table('leads', function (Blueprint $table) {
            $table->string('product_raw')->nullable()->after('product_name');
        });

        DB::table('leads')->whereNull('product_raw')->update(['product_raw' => DB::raw('product_name')]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('leads')->whereNotNull('product_raw')->update(['product_name' => DB::raw('product_raw')]);

        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn('product_raw');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('keywords');
        });
    }
};
