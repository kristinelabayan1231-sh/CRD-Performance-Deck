<?php

use App\Support\WorkingDate;
use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Leads added from a CRA's Pancake POS sale were filed on the sale's real day (Oct 17); with a working date set they
 * belong on the lead day the CRAs were working that day (Sept 17), as new ones now are (PosLeads). Only POS-added leads
 * still on their purchase day move; the purchase day (repeat_purchase_on) stays the real day.
 */
return new class extends Migration
{
    public function up(): void
    {
        $lag = WorkingDate::lagDays();

        if ($lag === 0) {
            return;
        }

        DB::table('leads')->where('order_id', 'like', 'POS-%')->whereNotNull('repeat_purchase_on')
            ->whereColumn('est_out_of_stock_date', 'repeat_purchase_on')
            ->select(['id', 'repeat_purchase_on'])
            ->chunkById(500, function ($leads) use ($lag) {
                foreach ($leads as $lead) {
                    DB::table('leads')->where('id', $lead->id)->update([
                        'est_out_of_stock_date' => CarbonImmutable::parse($lead->repeat_purchase_on)->subDays($lag)->toDateString(),
                    ]);
                }
            });
    }

    /**
     * Back on their purchase day, where they were.
     */
    public function down(): void
    {
        DB::table('leads')->where('order_id', 'like', 'POS-%')->whereNotNull('repeat_purchase_on')
            ->update(['est_out_of_stock_date' => DB::raw('repeat_purchase_on')]);
    }
};
