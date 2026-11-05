<?php

use App\Models\PcReportRow;
use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * PC Report: the Creation/Update Time is set per day, like Total Inquiries and Total Engagement, instead of once
     * per page row. A month range already set on a row moves to the day it was saved (segmentation timezone), the
     * closest guess to the day it was meant for; every other day starts blank. A split row still on its usual months
     * (PcReportRow::SPLIT, filled in by + or the October backfill) was never picked, so it isn't moved.
     */
    public function up(): void
    {
        Schema::table('pc_report_entries', function (Blueprint $table) {
            $table->date('from_month')->nullable()->after('engagements');
            $table->date('to_month')->nullable()->after('from_month');
        });

        $timezone = config('segmentation.timezone');
        foreach (DB::table('pc_report_rows')->whereNotNull('from_month')->get() as $row) {
            [$usualFrom, $usualTo] = PcReportRow::SPLIT[$row->category] ?? [null, null];
            if ($usualFrom !== null && substr($row->from_month, 0, 7) === $usualFrom && ($row->to_month === null ? $usualTo === null : substr($row->to_month, 0, 7) === $usualTo)) {
                continue;
            }

            $date = CarbonImmutable::parse($row->updated_at, 'UTC')->setTimezone($timezone)->toDateString();
            $key = ['user_id' => $row->user_id, 'page' => $row->page, 'category' => $row->category, 'date' => $date];

            DB::table('pc_report_entries')->where($key)->exists()
                ? DB::table('pc_report_entries')->where($key)->update(['from_month' => $row->from_month, 'to_month' => $row->to_month])
                : DB::table('pc_report_entries')->insert([...$key, 'from_month' => $row->from_month, 'to_month' => $row->to_month, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pc_report_entries', function (Blueprint $table) {
            $table->dropColumn(['from_month', 'to_month']);
        });
    }
};
