<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Actual Income: the amounts typed in for a month, one row per card and line. user_id is the CRA's card; null is
     * the overall CRD card.
     */
    public function up(): void
    {
        Schema::create('actual_income_inputs', function (Blueprint $table) {
            $table->id();
            $table->date('month');
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('field', 40);
            $table->decimal('amount', 14, 2);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['month', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('actual_income_inputs');
    }
};
