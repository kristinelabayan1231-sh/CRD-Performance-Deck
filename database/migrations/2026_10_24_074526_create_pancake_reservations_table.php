<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Reservations a CRA took in a Pancake conversation (Segmentation Tracker → Pending full view → Reserved - Pancake).
     * Kept apart from leads so they don't change any lead count.
     */
    public function up(): void
    {
        Schema::create('pancake_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('customer_name');
            $table->string('phone_number')->nullable();
            $table->string('phone_key')->nullable()->index();
            // Their last order, looked up when added (PancakeReservationLookup); blank for a customer with none.
            $table->string('product_name')->nullable();
            $table->unsignedInteger('qty')->nullable();
            $table->date('delivered_date')->nullable();
            $table->unsignedInteger('consumption_days')->nullable();
            $table->date('est_out_of_stock_date')->nullable();
            $table->string('lead_type')->nullable();
            $table->string('filled_from')->nullable();
            $table->string('status')->nullable();
            $table->foreignId('status_updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('status_updated_at')->nullable();
            $table->string('repeat_purchase')->nullable();
            $table->date('repeat_purchase_on')->nullable();
            $table->string('customer_tag')->nullable();
            $table->date('contact_date')->nullable();
            $table->string('contact_time')->nullable();
            $table->string('feedback')->nullable();
            $table->date('callback_date')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('notes_updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('notes_updated_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pancake_reservations');
    }
};
