<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The lead day a lead is listed under: its out-of-stock date, until the daily backlog fill hands it out
 * on a later day. Every lead-day view and report counts the lead on this day.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->date('listed_on')->nullable()->after('est_out_of_stock_date')->index();
        });

        DB::table('leads')->update(['listed_on' => DB::raw('est_out_of_stock_date')]);
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropIndex(['listed_on']);
            $table->dropColumn('listed_on');
        });
    }
};
