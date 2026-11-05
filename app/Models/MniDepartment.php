<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Monthly MNI: the department's typed-in amounts for a month and plan (Tax Allocation, Advertising Cost and the operating
 * costs), each split evenly across the CRAs.
 */
#[Fillable([
    'month', 'plan', 'updated_by', 'tax_allocation', 'advertising_cost', 'communication_allowance', 'miscellaneous_expenses',
    'product_research', 'magic_fund', 'company_assets', 'executive_benefits', 'office_miscellaneous', 'maintenance_expenses',
    'consultants', 'managers_allowance', 'birthday_cake_allowance', 'water_bill', 'internet', 'rent', 'electricity',
    'management_fee', 'business_development_fund', 'hmo_expense',
])]
class MniDepartment extends Model
{
    /** Operating costs typed in for the department, in statement order (Product Research to HMO Expense): field => label. */
    public const OPERATING = [
        'product_research' => 'Product Research',
        'magic_fund' => 'Magic Fund',
        'company_assets' => 'Company Assets',
        'executive_benefits' => 'Executive Benefits',
        'office_miscellaneous' => 'Office Miscellaneous',
        'maintenance_expenses' => 'Maintenance Expenses',
        'consultants' => 'Consultants',
        'managers_allowance' => "Manager's Allowance",
        'birthday_cake_allowance' => 'Birthday Cake Allowance',
        'water_bill' => 'Water Bill',
        'internet' => 'Internet',
        'rent' => 'Rent',
        'electricity' => 'Electricity',
        'management_fee' => 'Geniusmakers Management Fee',
        'business_development_fund' => 'Business Development Fund',
        'hmo_expense' => 'HMO Expense',
    ];

    /** The department's inputs: field => label. */
    public const FIELDS = [
        'tax_allocation' => 'Tax Allocation',
        'advertising_cost' => 'Advertising Cost',
        'communication_allowance' => 'Communication Allowance',
        'miscellaneous_expenses' => 'Miscellaneous expenses',
        ...self::OPERATING,
    ];

    /** Plans: key => label. */
    public const PLANS = ['1m' => '1M', '2m' => '2M'];

    protected function casts(): array
    {
        return ['month' => 'immutable_date', ...array_fill_keys(array_keys(self::FIELDS), 'float')];
    }
}
