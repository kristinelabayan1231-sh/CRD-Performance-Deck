<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Monthly MNI: the department's other operating costs (Product Research to HMO Expense), typed in and split evenly
     * across the CRAs.
     */
    private const COLUMNS = [
        'product_research', 'magic_fund', 'company_assets', 'executive_benefits', 'office_miscellaneous', 'maintenance_expenses',
        'consultants', 'managers_allowance', 'birthday_cake_allowance', 'water_bill', 'internet', 'rent', 'electricity',
        'management_fee', 'business_development_fund', 'hmo_expense',
    ];

    public function up(): void
    {
        Schema::table('mni_departments', function (Blueprint $table) {
            foreach (self::COLUMNS as $column) {
                $table->decimal($column, 14, 2)->nullable();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('mni_departments', function (Blueprint $table) {
            $table->dropColumn(self::COLUMNS);
        });
    }
};
