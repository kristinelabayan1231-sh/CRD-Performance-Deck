<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Services\PancakeSync;
use App\Support\WorkingDate;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

class BackfillCustomerDatabase extends Command
{
    /** Setting holding the last day backfilled. */
    public const DONE_THROUGH = 'customers.backfill_done_through';

    /** Setting set once the backfill has caught up; it never runs again. */
    public const FINISHED = 'customers.backfill_finished';

    protected $signature = 'customers:backfill
        {--days=4 : Days to fetch this run (never past the current window)}
        {--window : Finish the whole current window this run}';

    protected $description = 'One-time Customer Database backfill from Pancake POS, oldest first in 2-month windows, up to yesterday';

    public function handle(PancakeSync $sync): int
    {
        if (Setting::value(self::FINISHED)) {
            $this->info('The Customer Database backfill is finished; the regular syncs keep it current.');

            return self::SUCCESS;
        }

        $yesterday = WorkingDate::realToday()->subDay();
        $doneThrough = Setting::value(self::DONE_THROUGH);
        $start = $doneThrough ? CarbonImmutable::parse($doneThrough)->addDay() : CarbonImmutable::parse(config('customers.backfill_from'));

        if ($start->greaterThan($yesterday)) {
            return $this->finish();
        }

        $window = collect(self::windows($yesterday))->first(fn (array $w) => $start->lessThanOrEqualTo($w['end']));
        $end = $this->option('window') ? $window['end'] : $start->addDays(max(1, (int) $this->option('days')) - 1)->min($window['end']);
        $deliveredFrom = CarbonImmutable::parse(config('customers.delivered_from'));
        $logisticsFrom = CarbonImmutable::parse(config('customers.logistics_from'));

        for ($day = $start; $day->lessThanOrEqualTo($end); $day = $day->addDay()) {
            try {
                $line = "{$window['label']} · {$day->toDateString()}: {$sync->syncOrders($day)} orders";

                // Before the logistics report starts, the day's POS deliveries are the customers.
                if ($day->greaterThanOrEqualTo($deliveredFrom) && $day->lessThan($logisticsFrom)) {
                    $line .= ", {$sync->syncDeliveredCustomers($day)} deliveries";
                }

                $this->info($line.'.');
            } catch (Throwable $e) {
                // The next run starts again from this day.
                $this->error("{$day->toDateString()}: {$e->getMessage()}");

                return self::FAILURE;
            }

            Setting::put(self::DONE_THROUGH, $day->toDateString());
        }

        if ($end->equalTo($window['end'])) {
            $this->info("{$window['label']} done.");
        }

        return $end->greaterThanOrEqualTo($yesterday) ? $this->finish() : self::SUCCESS;
    }

    /**
     * Windows of customers.backfill_window_months months from customers.delivered_from up to $last;
     * the first also takes the order days before it (customers.backfill_from).
     *
     * @return list<array{start: CarbonImmutable, end: CarbonImmutable, label: string}>
     */
    public static function windows(CarbonImmutable $last): array
    {
        $months = max(1, (int) config('customers.backfill_window_months'));
        $windows = [];

        for ($from = CarbonImmutable::parse(config('customers.delivered_from'))->startOfMonth(); $from->lessThanOrEqualTo($last); $from = $from->addMonthsNoOverflow($months)) {
            $to = $from->addMonthsNoOverflow($months)->subDay()->min($last);
            $windows[] = [
                'start' => $windows ? $from : $from->min(CarbonImmutable::parse(config('customers.backfill_from'))),
                'end' => $to,
                'label' => $from->isSameMonth($to) ? $from->format('M Y') : $from->format('M').'–'.$to->format('M Y'),
            ];
        }

        return $windows;
    }

    private function finish(): int
    {
        Setting::put(self::FINISHED, now()->toIso8601String());
        $this->info('Customer Database backfill finished; the regular syncs take over from here.');

        return self::SUCCESS;
    }
}
