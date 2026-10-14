<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pancake's discount on the order, e.g. a GCash payment entered as a discount: Order Database sales add it
     * back to total_price. Null until the order is synced again (pancake:sync --discounts fills in older ones).
     */
    public function up(): void
    {
        Schema::table('pancake_orders', function (Blueprint $table) {
            $table->decimal('total_discount', 12, 2)->nullable()->after('shecom_sales');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pancake_orders', function (Blueprint $table) {
            $table->dropColumn('total_discount');
        });
    }
};
