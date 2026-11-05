<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Orders that left Canceled or Waiting for goods (e.g. recovered to Shipped), as the Pancake sync noticed
     * them; shown on Cancelled / Waiting Orders.
     */
    public function up(): void
    {
        Schema::create('order_status_changes', function (Blueprint $table) {
            $table->id();
            $table->string('pancake_order_id')->index();
            $table->unsignedSmallInteger('from_status');
            $table->unsignedSmallInteger('to_status')->nullable();
            $table->timestamps();
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_status_changes');
    }
};
