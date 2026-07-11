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
        // CRA-scoped metrics — inquiries counted by the CRA's assigned
        // customer-creation cohort, plus their manual tagging entry.
        Schema::create('cra_pc_day_stats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cra_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pc_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->unsignedInteger('inquiries')->nullable();
            $table->string('tagging')->nullable();
            $table->timestamps();

            $table->unique(['cra_id', 'pc_id', 'date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cra_pc_day_stats');
    }
};
