<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Actual Income: an amount typed on a staff card (a CRD - MC row such as the Junior AI Engineer, config
     * actual_income.staff) has its row here and no user_id.
     */
    public function up(): void
    {
        Schema::table('actual_income_inputs', function (Blueprint $table) {
            $table->foreignId('monthly_cost_row_id')->nullable()->after('user_id')->constrained()->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('actual_income_inputs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('monthly_cost_row_id');
        });
    }
};
