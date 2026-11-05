<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every delivered order the deck has seen, from the retention API (Shecom)
     * and from Pancake POS. When the retention API is down, the day's leads are
     * worked out from here with Settings → Product Consumption days.
     */
    public function up(): void
    {
        Schema::create('delivered_orders', function (Blueprint $table) {
            $table->id();
            // The shop's order number: Shecom's order_id = Pancake's display_id.
            $table->string('order_id')->unique();
            $table->string('tracking_number')->nullable();
            $table->string('customer_name');
            $table->string('phone_number');
            $table->string('product_raw');
            $table->unsignedInteger('qty');
            $table->date('delivered_date')->index();
            // Days per unit as the retention API gave them; null for Pancake-only orders.
            $table->unsignedInteger('consumption_days_per_unit')->nullable();
            $table->string('source'); // shecom | pancake
            $table->timestamps();
        });

        // Start from the orders already in the tracker.
        DB::table('leads')->orderBy('id')->chunk(500, function ($leads) {
            DB::table('delivered_orders')->insertOrIgnore($leads->map(fn ($lead) => [
                'order_id' => $lead->order_id,
                'tracking_number' => $lead->tracking_number,
                'customer_name' => $lead->customer_name,
                'phone_number' => $lead->phone_number,
                'product_raw' => $lead->product_raw ?? $lead->product_name,
                'qty' => $lead->qty,
                'delivered_date' => $lead->delivered_date,
                'consumption_days_per_unit' => $lead->consumption_days,
                'source' => 'shecom',
                'created_at' => now(),
                'updated_at' => now(),
            ])->all());
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('delivered_orders');
    }
};
