<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The leads held for an exclusive CRA's days ahead: the rest of the month's flat target.
     */
    public function up(): void
    {
        Schema::create('exclusive_reserved_leads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('month');
            $table->foreignId('lead_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('source', 20);
            $table->timestamps();
            $table->index(['user_id', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exclusive_reserved_leads');
    }
};
