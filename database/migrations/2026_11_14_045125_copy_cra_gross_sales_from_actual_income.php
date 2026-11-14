<?php

use App\Services\ActualIncome;
use App\Services\LeadGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Keep what CRA Gross Sales & IBSC showed when it stopped reading the Actual Income cards: each CRA's Gross Sales
     * and NET INCOME for every month with a card (a sheet month's CRAs, or a CRA with amounts typed in).
     */
    public function up(): void
    {
        $months = DB::table('actual_income_statements')->pluck('month')
            ->concat(DB::table('actual_income_inputs')->pluck('month'))
            ->map(fn ($month) => CarbonImmutable::parse($month)->startOfMonth()->toDateString())
            ->unique()->sort()->values();

        if ($months->isEmpty()) {
            return;
        }

        $income = app(ActualIncome::class);
        $cras = LeadGenerator::cras();
        $now = now();

        foreach ($months as $month) {
            $data = $income->month($cras, CarbonImmutable::parse($month));
            foreach ($data['shown'] as $cra) {
                if (! ($data['filled'][$cra->id] ?? false)) {
                    continue;
                }
                DB::table('cra_gross_sales')->updateOrInsert(['month' => $month, 'user_id' => $cra->id], [
                    'gross' => round((float) $data['cras'][$cra->id]['gross'], 2),
                    'net' => round((float) $data['cras'][$cra->id]['net_income'], 2),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    /**
     * Nothing to undo: dropping the table (the migration before) removes the copied figures.
     */
    public function down(): void {}
};
