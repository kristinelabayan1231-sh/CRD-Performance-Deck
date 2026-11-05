<?php

namespace App\Console\Commands;

use App\Services\PosLeads;
use App\Support\WorkingDate;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class RecordPosSales extends Command
{
    protected $signature = 'leads:record-pos-sales
        {from? : First order day (YYYY-MM-DD)}
        {to? : Last order day (YYYY-MM-DD). Defaults to the first.}
        {--last= : Instead of dates: the last this many days up to today (catches late orders, products listed later and CVR Tracker claims)}';

    protected $description = 'Record CRA Pancake POS sales in the Segmentation Tracker as repeat purchases, for days the Pancake sync did not (e.g. before this existed)';

    public function handle(PosLeads $posLeads): int
    {
        if ($this->option('last')) {
            $to = WorkingDate::realToday();
            $from = $to->subDays(max(1, (int) $this->option('last')) - 1);
        } elseif ($this->argument('from')) {
            $from = CarbonImmutable::parse($this->argument('from'));
            $to = CarbonImmutable::parse($this->argument('to') ?? $this->argument('from'));
        } else {
            $this->error('Give a first day, or --last=N.');

            return self::FAILURE;
        }

        if ($to->lessThan($from)) {
            $this->error('The last day is before the first.');

            return self::FAILURE;
        }

        $totals = ['added' => 0, 'marked' => 0];

        for ($day = $from; $day->lessThanOrEqualTo($to); $day = $day->addDay()) {
            $result = $posLeads->record($day);
            $totals = ['added' => $totals['added'] + $result['added'], 'marked' => $totals['marked'] + $result['marked']];
            $this->line("{$day->toDateString()}: {$result['added']} added, {$result['marked']} marked Yes");
        }

        $this->info("Done: {$totals['added']} customers added to the tracker, {$totals['marked']} tracker leads marked Yes.");

        return self::SUCCESS;
    }
}
