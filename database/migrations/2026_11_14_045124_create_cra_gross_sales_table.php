<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * CRA Gross Sales & IBSC: each CRA's Gross Sales and Net Income per month, typed in on the page.
     */
    public function up(): void
    {
        Schema::create('cra_gross_sales', function (Blueprint $table) {
            $table->id();
            $table->date('month');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->decimal('gross', 14, 2)->nullable();
            $table->decimal('net', 14, 2)->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['month', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cra_gross_sales');
    }
};
