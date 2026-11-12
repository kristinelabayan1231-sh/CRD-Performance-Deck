<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Actual Income taken from the CRD Sales Report sheet: a month's card with every line as the sheet has it (line key
     * => amount). user_id is the CRA's card; null is the overall CRD card.
     */
    public function up(): void
    {
        Schema::create('actual_income_statements', function (Blueprint $table) {
            $table->id();
            $table->date('month');
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->json('figures');
            $table->timestamps();

            $table->index(['month', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('actual_income_statements');
    }
};
