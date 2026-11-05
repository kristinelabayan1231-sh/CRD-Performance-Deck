<?php

use App\Models\Setting;
use App\Services\DailyIncomeStatement;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Daily I.S.: Nov 1 and Nov 2, 2026 take Product Cost from the CRD Sales Report sheet, per CRA (display name) and
     * product card (PRODUCT_ORDER key); products not listed are ₱0. Nov 1 adds up to ₱7,649, Nov 2 to ₱6,632.
     *
     * @var array<string, array<string, array<string, float>>>
     */
    private array $costs = [
        '2026-11-01' => [
            'Joanna Rose' => ['clearsight' => 385, 'pterygium' => 930, 'sinuxyl' => 70, 'ginseng' => 108, 'nutrilay' => 490],
            'Regina' => ['clearsight' => 245, 'pterygium' => 480, 'sinuxyl' => 350, 'ginseng' => 144, 'nutrilay' => 525],
            'July Ann' => ['clearsight' => 385, 'pterygium' => 750, 'sinuxyl' => 105, 'audi' => 150, 'ginseng' => 72, 'nutrilay' => 700],
            'Rose-An' => ['clearsight' => 175, 'pterygium' => 960, 'sinuxyl' => 420, 'audi' => 30, 'nutrilay' => 175],
        ],
        '2026-11-02' => [
            'Joanna Rose' => ['clearsight' => 175, 'pterygium' => 990, 'sinuxyl' => 175, 'ginseng' => 180, 'nutrilay' => 175],
            'Regina' => ['clearsight' => 595, 'pterygium' => 840, 'canpro' => 450, 'audi' => 120, 'ginseng' => 72],
            'July Ann' => ['clearsight' => 350, 'pterygium' => 630, 'audi' => 210, 'ginseng' => 180, 'primeman' => 225, 'cystora' => 70],
            'Lhea' => ['clearsight' => 105, 'pterygium' => 600, 'sinuxyl' => 140, 'nutrilay' => 350],
        ],
    ];

    public function up(): void
    {
        Setting::put(DailyIncomeStatement::PRODUCT_COST_OVERRIDES_KEY, json_encode([...DailyIncomeStatement::productCostOverrides(), ...$this->costs]));
    }

    public function down(): void
    {
        $left = array_diff_key(DailyIncomeStatement::productCostOverrides(), $this->costs);
        Setting::put(DailyIncomeStatement::PRODUCT_COST_OVERRIDES_KEY, $left === [] ? null : json_encode($left));
    }
};
