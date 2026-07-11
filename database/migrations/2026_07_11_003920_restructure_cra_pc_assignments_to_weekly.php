<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Cohorts are no longer open-ended ("effective from X onward") — every
     * fixed 7-day block (1–7, 8–14, 15–21, 22–28, 29–end) gets its own
     * explicit cohort row per (CRA, PC), so a week with nothing entered is
     * unambiguously "not set yet" rather than silently inheriting the past.
     */
    public function up(): void
    {
        Schema::table('cra_pc_assignments', function (Blueprint $table) {
            $table->date('week_start')->nullable()->after('pc_id');
        });

        // Backfill: map each row's old effective_date to the start of the
        // 7-day block it falls in.
        DB::table('cra_pc_assignments')->orderBy('id')->each(function ($row) {
            $effective = Carbon::parse($row->effective_date);
            $blockIndex = intdiv($effective->day - 1, 7); // 0..4
            $weekStart = $effective->copy()->startOfMonth()->addDays($blockIndex * 7);

            DB::table('cra_pc_assignments')
                ->where('id', $row->id)
                ->update(['week_start' => $weekStart->toDateString()]);
        });

        Schema::table('cra_pc_assignments', function (Blueprint $table) {
            $table->date('week_start')->nullable(false)->change();
            $table->dropIndex(['cra_id', 'pc_id', 'effective_date']);
            $table->dropColumn('effective_date');
            $table->unique(['cra_id', 'pc_id', 'week_start']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cra_pc_assignments', function (Blueprint $table) {
            $table->date('effective_date')->nullable();
        });

        DB::table('cra_pc_assignments')->orderBy('id')->each(function ($row) {
            DB::table('cra_pc_assignments')
                ->where('id', $row->id)
                ->update(['effective_date' => $row->week_start]);
        });

        Schema::table('cra_pc_assignments', function (Blueprint $table) {
            $table->date('effective_date')->nullable(false)->change();
            $table->dropUnique(['cra_id', 'pc_id', 'week_start']);
            $table->dropColumn('week_start');
            $table->index(['cra_id', 'pc_id', 'effective_date']);
        });
    }
};
