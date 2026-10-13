<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * CRD - MC: every row's monthly salary is now typed in. Trainee, Probationary and Regular rows keep
     * what they were paid (daily rate × working days) as their salary and become fixed.
     */
    public function up(): void
    {
        $setting = fn (string $key) => (float) (DB::table('settings')->where('key', 'monthly_cost.'.$key)->value('value')
            ?? config('monthly_cost.defaults.'.$key));
        $days = $setting('working_days');

        DB::table('monthly_cost_rows')->where('pay_type', '!=', 'fixed')->get(['id', 'pay_type'])
            ->each(fn (object $row) => DB::table('monthly_cost_rows')->where('id', $row->id)->update([
                'pay_type' => 'fixed',
                'salary' => round($setting('rate_'.$row->pay_type) * $days, 2),
            ]));
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
