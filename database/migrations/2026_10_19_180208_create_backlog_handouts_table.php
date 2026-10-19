<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who received each backlog lead and on which lead day (the daily fill or a transfer), for Segmentation
 * Productivity's Backlog progress. lead_moves keeps where a lead left from; this keeps who got it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backlog_handouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('handed_on');
            $table->string('source', 20);
            $table->timestamps();

            $table->index(['user_id', 'handed_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backlog_handouts');
    }
};
