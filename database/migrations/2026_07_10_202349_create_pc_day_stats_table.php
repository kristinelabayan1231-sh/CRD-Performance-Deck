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
        // PC-level metrics from Pancake's users_engagements — one row per
        // PC per day, shared by every CRA working that PC.
        Schema::create('pc_day_stats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pc_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->unsignedInteger('engagement')->default(0);
            $table->unsignedInteger('orders')->default(0);
            $table->timestamps();

            $table->unique(['pc_id', 'date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pc_day_stats');
    }
};
