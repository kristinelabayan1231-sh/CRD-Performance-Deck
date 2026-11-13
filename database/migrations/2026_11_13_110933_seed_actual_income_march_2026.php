<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** The CRAs on March's cards, by display name in the app. */
    private const CRAS = ['Joanna Rose', 'Regina', 'July Ann'];

    private const MONTH = '2026-03-01';

    /**
     * Actual Income for March 2026, every line as in the CRD Sales Report sheet. 'CRD' is the overall CRD card.
     * Left as it is when March already has sheet figures; a database without the CRAs gets nothing.
     *
     * @var array<string, array<string, float|null>>
     */
    private array $cards = [
        'CRD' => ['target_leads' => 1381511, 'orders' => 2365, 'conversion' => 0.0017, 'aov' => 806.65, 'gross' => 1908093, 'cancelled' => 45951, 'unfulfilled' => 0, 'fulfilled' => 0, 'returns' => 242616, 'delivered' => 1619526, 'tax' => 100000, 'product_cost' => 213600, 'gross_profit' => 1305926, 'advertising' => 0, 'ads_vat' => 0, 'shipping' => 164570, 'cod' => 32390.52, 'vat_on_cod' => 3886.86, 'fulfillment' => 57800, 'product_research' => 0, 'packaging' => 0, 'selling' => 258647.38, 'salaries' => 162958.36, 'thirteenth_month' => 4371.1, 'sil' => 0, 'gov_benefits' => 7083.1, 'communication' => 404, 'misc' => 4570.65, 'magic_fund' => 0, 'company_assets' => 20181.41, 'executive_benefits' => 17279.79, 'office_miscellaneous' => 4118, 'maintenance_expenses' => 20137.27, 'consultants' => 5166.67, 'managers_allowance' => 0, 'birthday_cake_allowance' => 485, 'water_bill' => 93.29, 'internet' => 4462.66, 'rent' => 36673.33, 'electricity' => 25759.81, 'management_fee' => 33333.33, 'business_development_fund' => 25000, 'hmo_expense' => 11526.84, 'operating' => 383604.61, 'net_income' => 663674.01],
        'Joanna Rose' => ['target_leads' => 513648, 'orders' => 800, 'conversion' => 0.0016, 'aov' => 789.79, 'gross' => 631831, 'cancelled' => 15383, 'unfulfilled' => 0, 'fulfilled' => 0, 'returns' => 86658, 'delivered' => 529790, 'tax' => 33333.33, 'product_cost' => 68810, 'gross_profit' => 427646.67, 'advertising' => 0, 'ads_vat' => 0, 'shipping' => 55612, 'cod' => 10595.8, 'vat_on_cod' => 1271.5, 'fulfillment' => 19450, 'product_research' => 0, 'packaging' => 0, 'selling' => 86929.3, 'salaries' => 54319.45, 'thirteenth_month' => 1457.03, 'sil' => 0, 'gov_benefits' => 2361.03, 'communication' => 134.67, 'misc' => 1523.55, 'magic_fund' => 0, 'company_assets' => 6727.14, 'executive_benefits' => 5759.93, 'office_miscellaneous' => 1372.67, 'maintenance_expenses' => 6712.42, 'consultants' => 1722.22, 'managers_allowance' => 0, 'birthday_cake_allowance' => 161.67, 'water_bill' => 31.1, 'internet' => 1487.55, 'rent' => 12224.44, 'electricity' => 8586.6, 'management_fee' => 11111.11, 'business_development_fund' => 8333.33, 'hmo_expense' => 3842.28, 'operating' => 127868.2, 'net_income' => 212849.17],
        'Regina' => ['target_leads' => 477452.5, 'orders' => 810, 'conversion' => 0.0017, 'aov' => 826.97, 'gross' => 669848, 'cancelled' => 6691, 'unfulfilled' => 0, 'fulfilled' => 0, 'returns' => 58920, 'delivered' => 604237, 'tax' => 33333.33, 'product_cost' => 78475, 'gross_profit' => 492428.67, 'advertising' => 0, 'ads_vat' => 0, 'shipping' => 57152, 'cod' => 12084.74, 'vat_on_cod' => 1450.17, 'fulfillment' => 20125, 'product_research' => 0, 'packaging' => 0, 'selling' => 90811.91, 'salaries' => 54319.45, 'thirteenth_month' => 1457.03, 'sil' => 0, 'gov_benefits' => 2361.03, 'communication' => 134.67, 'misc' => 1523.55, 'magic_fund' => 0, 'company_assets' => 6727.14, 'executive_benefits' => 5759.93, 'office_miscellaneous' => 1372.67, 'maintenance_expenses' => 6712.42, 'consultants' => 1722.22, 'managers_allowance' => 0, 'birthday_cake_allowance' => 161.67, 'water_bill' => 31.1, 'internet' => 1487.55, 'rent' => 12224.44, 'electricity' => 8586.6, 'management_fee' => 11111.11, 'business_development_fund' => 8333.33, 'hmo_expense' => 3842.28, 'operating' => 127868.2, 'net_income' => 273748.55],
        'July Ann' => ['target_leads' => 390410.5, 'orders' => 755, 'conversion' => 0.0019, 'aov' => 803.2, 'gross' => 606414, 'cancelled' => 23877, 'unfulfilled' => 0, 'fulfilled' => 0, 'returns' => 97038, 'delivered' => 485499, 'tax' => 33333.33, 'product_cost' => 66315, 'gross_profit' => 385850.67, 'advertising' => 0, 'ads_vat' => 0, 'shipping' => 51806, 'cod' => 9709.98, 'vat_on_cod' => 1165.2, 'fulfillment' => 18225, 'product_research' => 0, 'packaging' => 0, 'selling' => 80906.18, 'salaries' => 54319.45, 'thirteenth_month' => 1457.03, 'sil' => 0, 'gov_benefits' => 2361.03, 'communication' => 134.67, 'misc' => 1523.55, 'magic_fund' => 0, 'company_assets' => 6727.14, 'executive_benefits' => 5759.93, 'office_miscellaneous' => 1372.67, 'maintenance_expenses' => 6712.42, 'consultants' => 1722.22, 'managers_allowance' => 0, 'birthday_cake_allowance' => 161.67, 'water_bill' => 31.1, 'internet' => 1487.55, 'rent' => 12224.44, 'electricity' => 8586.6, 'management_fee' => 11111.11, 'business_development_fund' => 8333.33, 'hmo_expense' => 3842.28, 'operating' => 127868.2, 'net_income' => 177076.29],
    ];

    public function up(): void
    {
        $users = DB::table('users')->whereIn('display_name', self::CRAS)->pluck('id', 'display_name')->all();
        // A database without the CRAs (a fresh or test one) gets nothing.
        if ($users === [] || DB::table('actual_income_statements')->whereDate('month', self::MONTH)->exists()) {
            return;
        }

        foreach ($this->cards as $card => $figures) {
            if ($card !== 'CRD' && ! isset($users[$card])) {
                continue;
            }

            DB::table('actual_income_statements')->insert([
                'month' => self::MONTH, 'user_id' => $card === 'CRD' ? null : $users[$card], 'figures' => json_encode($figures),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('actual_income_statements')->whereDate('month', self::MONTH)->delete();
    }
};
