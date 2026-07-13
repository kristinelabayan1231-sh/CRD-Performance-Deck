<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * All four cohort fields NULL on a row now means "this PC is
     * explicitly worked without a cohort this week" — distinct from
     * having no row at all, which still means "not set yet" and keeps
     * the weekly prompt nagging.
     */
    public function up(): void
    {
        Schema::table('cra_pc_assignments', function (Blueprint $table) {
            $table->unsignedSmallInteger('cohort_from_year')->nullable()->change();
            $table->unsignedTinyInteger('cohort_from_month')->nullable()->change();
            $table->unsignedSmallInteger('cohort_to_year')->nullable()->change();
            $table->unsignedTinyInteger('cohort_to_month')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('cra_pc_assignments', function (Blueprint $table) {
            $table->unsignedSmallInteger('cohort_from_year')->nullable(false)->change();
            $table->unsignedTinyInteger('cohort_from_month')->nullable(false)->change();
            $table->unsignedSmallInteger('cohort_to_year')->nullable(false)->change();
            $table->unsignedTinyInteger('cohort_to_month')->nullable(false)->change();
        });
    }
};
