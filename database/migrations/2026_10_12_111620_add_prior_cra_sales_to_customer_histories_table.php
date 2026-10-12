<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Each earlier CRA-handled order (when, amount, seller), so the High AOV first-reorder rule
     * sees orders from before the Customer Database's first day. Null until looked up again.
     */
    public function up(): void
    {
        Schema::table('customer_histories', function (Blueprint $table) {
            $table->json('prior_cra_sales')->nullable()->after('prior_last_ordered_on');
        });
    }

    public function down(): void
    {
        Schema::table('customer_histories', function (Blueprint $table) {
            $table->dropColumn('prior_cra_sales');
        });
    }
};
