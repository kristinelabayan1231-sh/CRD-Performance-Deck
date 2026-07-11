<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cra_call_stats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cra_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->unsignedInteger('total_calls');
            $table->unsignedInteger('answered_calls');
            $table->timestamps();
            $table->unique(['cra_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cra_call_stats');
    }
};
