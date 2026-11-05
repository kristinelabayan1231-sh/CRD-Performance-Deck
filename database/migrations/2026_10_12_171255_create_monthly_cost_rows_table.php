<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * CRD - MC: one row per position in the Customer Retention Dept. (a vacant one has no name).
     */
    public function up(): void
    {
        Schema::create('monthly_cost_rows', function (Blueprint $table) {
            $table->id();
            $table->string('position');
            $table->string('name')->nullable();
            $table->string('pay_type', 20);
            // Only a fixed salary is stored; the others are their daily rate × working days.
            $table->decimal('salary', 12, 2)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monthly_cost_rows');
    }
};
