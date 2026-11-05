<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Monthly MNI: October 2026, 1M and 2M, as typed in the CRD Sales Report sheets. Only when all five CRAs are found (by
     * display name), and never over inputs already saved for that month and plan.
     *
     * Each CRA: [orders, AOV]; every CRA has a 0.15% conversion rate, 5% cancelled, 9.5% returns and 12% product cost.
     *
     * @var array<string, array<string, array{0: int, 1: float}>>
     */
    private array $cras = [
        '1m' => ['Joanna Rose' => [730, 880], 'Regina' => [619, 900], 'July Ann' => [620, 900], 'Lhea' => [618, 900], 'Rose-An' => [612, 900]],
        '2m' => ['Joanna Rose' => [986, 900], 'Regina' => [986, 900], 'July Ann' => [986, 900], 'Lhea' => [986, 900], 'Rose-An' => [976, 900]],
    ];

    /** @var array<string, array<string, float>> */
    private array $department = [
        '1m' => [
            'tax_allocation' => 100000, 'advertising_cost' => 0, 'communication_allowance' => 810, 'miscellaneous_expenses' => 7300,
            'product_research' => 5000, 'magic_fund' => 6666.67, 'company_assets' => 20181.41, 'executive_benefits' => 19333.33,
            'office_miscellaneous' => 19233.33, 'maintenance_expenses' => 8933.33, 'consultants' => 5786.67, 'managers_allowance' => 0,
            'birthday_cake_allowance' => 600, 'water_bill' => 203.33, 'internet' => 4565.68, 'rent' => 37650, 'electricity' => 30000,
            'management_fee' => 33333.33, 'business_development_fund' => 25000, 'hmo_expense' => 11526.84,
        ],
        '2m' => [
            'tax_allocation' => 100000, 'advertising_cost' => 0, 'communication_allowance' => 810, 'miscellaneous_expenses' => 7300,
            'product_research' => 5000, 'magic_fund' => 3333.33, 'company_assets' => 20181.41, 'executive_benefits' => 19333.33,
            'office_miscellaneous' => 9733.33, 'maintenance_expenses' => 26266.67, 'consultants' => 5166.67, 'managers_allowance' => 0,
            'birthday_cake_allowance' => 1600, 'water_bill' => 203.33, 'internet' => 4532.33, 'rent' => 37650, 'electricity' => 38666.67,
            'management_fee' => 33333.33, 'business_development_fund' => 25000, 'hmo_expense' => 11526.84,
        ],
    ];

    public function up(): void
    {
        $names = array_keys($this->cras['1m']);
        $ids = DB::table('users')->join('roles', 'roles.id', '=', 'users.role_id')
            ->where('roles.slug', 'cra')->whereIn('users.display_name', $names)
            ->pluck('users.id', 'users.display_name');

        if ($ids->count() !== count($names)) {
            return;
        }

        $now = now();

        foreach ($this->cras as $plan => $targets) {
            $key = ['month' => '2026-10-01', 'plan' => $plan];

            DB::table('mni_inputs')->insertOrIgnore(collect($targets)->map(fn (array $target, string $name) => [
                ...$key, 'user_id' => $ids[$name], 'orders' => $target[0], 'conversion_rate' => 0.15, 'aov' => $target[1],
                'cancelled_pct' => 5, 'returns_pct' => 9.5, 'product_cost_pct' => 12, 'created_at' => $now, 'updated_at' => $now,
            ])->values()->all());

            DB::table('mni_departments')->insertOrIgnore([...$key, ...$this->department[$plan], 'created_at' => $now, 'updated_at' => $now]);
        }
    }

    /**
     * Left in place: by now they may have been edited in the module.
     */
    public function down(): void {}
};
