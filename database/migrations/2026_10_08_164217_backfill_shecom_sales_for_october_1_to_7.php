<?php

use App\Services\PancakeSync;
use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * One-time: Shecom sales (without the child TSD row) for Oct 1–7, 2026,
     * synced before gross sales used them. Same as
     * `pancake:sync --sales --from=2026-10-01 --to=2026-10-07`, for servers
     * without a shell. Later days fill in through the regular sync; if Shecom
     * is down or SHECOM_SALES_API_KEY is missing, the deploy still goes ahead.
     */
    public function up(): void
    {
        if (app()->runningUnitTests()) {
            return;
        }

        rescue(fn () => app(PancakeSync::class)->syncSales(
            CarbonImmutable::parse('2026-10-01'),
            CarbonImmutable::parse('2026-10-07'),
        ));
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
