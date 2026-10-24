<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cold leads' contact attempts, logged by the CRA (one per lead per day). They stay with the lead when it moves to another CRA.
     */
    public function up(): void
    {
        Schema::create('lead_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->date('attempted_on');
            $table->timestamps();

            $table->unique(['lead_id', 'attempted_on']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lead_attempts');
    }
};
