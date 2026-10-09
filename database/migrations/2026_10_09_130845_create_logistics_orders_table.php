<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every FSD- and CRD-delivered order in the logistics retention report,
     * kept for the Customer Database. Amounts and statuses come from Pancake POS.
     */
    public function up(): void
    {
        Schema::create('logistics_orders', function (Blueprint $table) {
            $table->id();
            // The shop's order number: Shecom's order_id = Pancake's display_id.
            $table->string('order_id')->unique();
            $table->string('team', 10); // fsd | crd
            $table->string('customer_name');
            $table->string('phone_number');
            // Last 10 digits, the same key leads and Pancake orders are matched on.
            $table->string('phone_key')->index();
            $table->string('product');
            // Known for CRD orders on the out-of-stock list only.
            $table->unsignedInteger('qty')->nullable();
            $table->date('delivered_date')->index();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('logistics_orders');
    }
};
