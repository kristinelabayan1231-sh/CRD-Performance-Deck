<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Monthly MNI: each CRA's typed-in targets for a month and plan (1M / 2M), and the department's Tax Allocation and
     * Advertising Cost for it (split evenly across the CRAs). Percentages are stored as typed (5 = 5%).
     */
    public function up(): void
    {
        Schema::create('mni_inputs', function (Blueprint $table) {
            $table->id();
            $table->date('month');
            $table->string('plan', 4);
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('orders')->nullable();
            $table->decimal('conversion_rate', 8, 4)->nullable();
            $table->decimal('aov', 12, 2)->nullable();
            $table->decimal('cancelled_pct', 6, 2)->nullable();
            $table->decimal('returns_pct', 6, 2)->nullable();
            $table->decimal('product_cost_pct', 6, 2)->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['month', 'plan', 'user_id']);
        });

        Schema::create('mni_departments', function (Blueprint $table) {
            $table->id();
            $table->date('month');
            $table->string('plan', 4);
            $table->decimal('tax_allocation', 14, 2)->nullable();
            $table->decimal('advertising_cost', 14, 2)->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['month', 'plan']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mni_departments');
        Schema::dropIfExists('mni_inputs');
    }
};
