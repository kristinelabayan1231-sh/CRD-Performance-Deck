<?php

namespace App\Console\Commands;

use App\Services\PancakeSync;
use App\Support\WorkingDate;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

class BackfillMissingDays extends Command
{
    protected $signature = 'pancake:backfill-missing
        {--from=2025-06-01 : First day to check (YYYY-MM-DD)}
        {--min=50 : A day with fewer Pancake orders than this is fetched again (a normal day has about 300)}
        {--dry-run : Only list the days that would be fetched}';

    protected $description = 'Fetch again, newest first, every past day the app missed (fewer orders than --min), e.g. while it was asleep; each day only once';

    /** Set while a run is going and refreshed after every day, so a second run (deploy + hourly schedule) stops at once. */
    private const RUNNING = 'pancake:backfill-missing:running';

    /** How long a run counts as going without finishing a day; a run cut off by a restart frees it after this. */
    private const RUNNING_SECONDS = 1200;

    public function handle(PancakeSync $sync): int
    {
        $days = $this->missingDays();

        if ($this->option('dry-run')) {
            $this->info(count($days).' days to fetch'.($days ? ': '.implode(', ', $days) : '.'));

            return self::SUCCESS;
        }

        if ($days === []) {
            $this->info('Pancake backfill: no missing days.');

            return self::SUCCESS;
        }

        if (! Cache::add(self::RUNNING, true, self::RUNNING_SECONDS)) {
            $this->info('Pancake backfill: already running.');

            return self::SUCCESS;
        }

        $failed = 0;

        try {
            foreach ($days as $i => $date) {
                Cache::put(self::RUNNING, true, self::RUNNING_SECONDS);
                $position = '['.($i + 1).'/'.count($days).']';

                try {
                    $result = $sync->sync(CarbonImmutable::parse($date));
                    // Remembered even when the day still has few orders (a holiday), so it isn't fetched on every run.
                    Cache::forever(self::doneKey($date), now()->toIso8601String());
                    $this->info("Pancake backfill {$position} {$date}: {$result['orders']} orders, {$result['staff']} staff with engagements, {$result['sales']} Shecom sales.");
                } catch (Throwable $e) {
                    // Not remembered: the next run tries this day again.
                    $failed++;
                    $this->error("Pancake backfill {$position} {$date}: {$e->getMessage()}");
                }
            }
        } finally {
            Cache::forget(self::RUNNING);
        }

        $this->info('Pancake backfill: ALL DONE'.($failed ? ", {$failed} days failed and will be tried again on the next run." : '.'));

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Past days from --from to yesterday with fewer saved orders than --min, not fetched by an earlier run, newest first.
     *
     * @return list<string>
     */
    private function missingDays(): array
    {
        $from = CarbonImmutable::parse($this->option('from'));
        $yesterday = WorkingDate::realToday()->subDay();
        $min = (int) $this->option('min');

        $orders = DB::table('pancake_orders')
            ->whereBetween('ordered_on', [$from->toDateString(), $yesterday->toDateString()])
            ->selectRaw('ordered_on as day, count(*) as orders')
            ->groupBy('ordered_on')
            ->pluck('orders', 'day')
            ->mapWithKeys(fn ($orders, $day) => [substr((string) $day, 0, 10) => (int) $orders]);

        $days = [];
        for ($day = $yesterday; $day->greaterThanOrEqualTo($from); $day = $day->subDay()) {
            $date = $day->toDateString();
            if (($orders[$date] ?? 0) < $min && ! Cache::has(self::doneKey($date))) {
                $days[] = $date;
            }
        }

        return $days;
    }

    private static function doneKey(string $date): string
    {
        return 'pancake:backfill-missing:done:'.$date;
    }
}
