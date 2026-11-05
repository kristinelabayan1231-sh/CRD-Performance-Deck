<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Each CRD customer's CRA-handled delivered orders from before the Customer
     * Database's first day, looked up once in Pancake POS by contact number, so
     * Retained vs Repeat counts their earlier history too.
     */
    public function up(): void
    {
        Schema::create('customer_histories', function (Blueprint $table) {
            $table->id();
            $table->string('phone_key')->unique();
            $table->unsignedInteger('prior_cra_orders')->default(0);
            $table->date('prior_last_ordered_on')->nullable();
            $table->timestamp('checked_at');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customer_histories');
    }
};
