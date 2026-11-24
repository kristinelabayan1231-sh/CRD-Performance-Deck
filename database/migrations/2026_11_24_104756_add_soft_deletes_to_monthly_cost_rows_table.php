<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * CRD - MC: a deleted row is kept with the time it was removed, so it still counts on the days before (MonthlyCostRow::countsOn).
     */
    public function up(): void
    {
        Schema::table('monthly_cost_rows', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('monthly_cost_rows', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
