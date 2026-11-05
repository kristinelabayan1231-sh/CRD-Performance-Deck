<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A lead the backlog fill moved off a CRA's lead day, still untouched. The day it left keeps counting it
 * (listed, not catered) so that day's totals never change after the fact.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_moves', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assigned_to')->constrained('users')->cascadeOnDelete();
            $table->date('listed_on');
            $table->date('moved_to');
            $table->timestamps();
            $table->index(['assigned_to', 'listed_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_moves');
    }
};
