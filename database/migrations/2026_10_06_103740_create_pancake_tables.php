<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Daily copies of Pancake data for Segmentation Productivity: chat
     * engagements per staff account, and POS orders.
     */
    public function up(): void
    {
        Schema::create('pancake_engagements', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->string('pancake_user_id');
            // Normalised staff name (upper case, single spaces) for matching users.pancake_name.
            $table->string('staff_name')->index();
            // Chat → Analytics → Engagements → Customer engagement, summed over every page.
            $table->unsignedInteger('engagements')->default(0);
            $table->timestamps();

            $table->unique(['date', 'pancake_user_id']);
        });

        Schema::create('pancake_orders', function (Blueprint $table) {
            $table->id();
            $table->string('pancake_order_id')->unique();
            // Day the order was created, in the segmentation timezone.
            $table->date('ordered_on')->index();
            $table->timestamp('ordered_at')->nullable();
            $table->string('seller_pancake_id')->nullable();
            $table->string('seller_name')->nullable()->index();
            $table->string('customer_name')->nullable();
            $table->string('phone_number')->nullable();
            // Last 10 digits, the same key leads are matched on.
            $table->string('phone_key')->nullable()->index();
            $table->unsignedSmallInteger('status')->nullable();
            $table->string('status_name')->nullable();
            $table->decimal('total_price', 12, 2)->default(0);
            $table->string('page_name')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pancake_orders');
        Schema::dropIfExists('pancake_engagements');
    }
};
