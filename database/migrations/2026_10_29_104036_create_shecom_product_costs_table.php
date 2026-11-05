<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Shecom's cost of each Pancake item (e.g. "2 Bottles Sinuxyl") from the day it took effect, one row per cost seen,
     * so a later change doesn't rewrite earlier days. A null effective_from counts from the start.
     */
    public function up(): void
    {
        Schema::create('shecom_product_costs', function (Blueprint $table) {
            $table->id();
            $table->string('product');
            $table->decimal('cost', 12, 2);
            $table->date('effective_from')->nullable();
            $table->timestamps();

            $table->index(['product', 'effective_from']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shecom_product_costs');
    }
};
