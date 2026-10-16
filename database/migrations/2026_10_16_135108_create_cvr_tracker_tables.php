<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * CVR Tracker: what a CRA picks per order (Upsell, Type of Order, Point of Contact) and types per day (Unconfirmed).
     */
    public function up(): void
    {
        Schema::create('cvr_order_entries', function (Blueprint $table) {
            $table->id();
            $table->string('pancake_order_id')->unique();
            $table->string('upsell', 20)->nullable();
            // Only set when the CRA changed it from the order's CRD tag.
            $table->string('order_type', 20)->nullable();
            $table->string('point_of_contact', 20)->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('cvr_day_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->unsignedInteger('unconfirmed')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cvr_day_entries');
        Schema::dropIfExists('cvr_order_entries');
    }
};
