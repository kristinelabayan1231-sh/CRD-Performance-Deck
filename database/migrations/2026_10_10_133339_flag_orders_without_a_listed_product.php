<?php

use App\Services\ProductCatalog;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Only products on the Product Consumption list count: tag the orders saved so far that have
     * none of them, so sales and the Customer Database leave them out. New orders are tagged as saved.
     */
    public function up(): void
    {
        set_time_limit(0);
        app(ProductCatalog::class)->flagUnlistedOrders();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
