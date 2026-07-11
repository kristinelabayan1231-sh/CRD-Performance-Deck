<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('cra_pc_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cra_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pc_id')->constrained()->cascadeOnDelete();

            // Customer-creation-time cohort this CRA works on this PC
            // (e.g. "May 2025 – July 2025").
            $table->unsignedSmallInteger('cohort_from_year');
            $table->unsignedTinyInteger('cohort_from_month');
            $table->unsignedSmallInteger('cohort_to_year');
            $table->unsignedTinyInteger('cohort_to_month');

            // The cohort can be reassigned over time; the row whose
            // effective_date is the latest one <= a given day wins.
            $table->date('effective_date');

            $table->timestamps();

            $table->index(['cra_id', 'pc_id', 'effective_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cra_pc_assignments');
    }
};
