<?php

namespace App\Console\Commands;

use App\Models\Lead;
use App\Models\User;
use App\Services\ExclusiveFsdFill;
use App\Services\ExclusiveLeads;
use Illuminate\Console\Command;

class FillExclusiveFsdLeads extends Command
{
    protected $signature = 'leads:fill-exclusive-fsd
        {--apply : Add the leads. Without it, only reports what would be added.}
        {--count= : How many to add. Defaults to what the month is short of its target.}';

    protected $description = "Add FSD deliveries that never became leads, held for the exclusive CRA, to reach the month's target";

    public function handle(ExclusiveLeads $exclusive, ExclusiveFsdFill $fill): int
    {
        $day = Lead::today()->max(ExclusiveLeads::flatMonthFrom());

        foreach (User::exclusiveOn($day)->get() as $cra) {
            $short = $exclusive->monthShortfall($cra, $day);
            $wanted = $this->option('count') !== null ? (int) $this->option('count') : $short;
            $leads = $fill->candidates($cra, $day, $wanted);

            $this->info("{$cra->displayName()} for lead day {$day->toDateString()}: short by {$short}, found {$leads->count()} of {$wanted} wanted.");
            $leads->countBy(fn (array $lead) => substr($lead['delivered_date'], 0, 7))->sortKeysDesc()
                ->each(fn (int $count, string $month) => $this->line("  Delivered {$month}: {$count}"));
            $leads->countBy('product_name')->sortDesc()
                ->each(fn (int $count, string $product) => $this->line("  {$product}: {$count}"));

            if ($this->option('apply')) {
                $this->info('  Added '.$fill->add($cra, $day, $leads).' leads.');
            }
        }

        if (! $this->option('apply')) {
            $this->comment('Nothing was added. Run again with --apply to add them.');
        }

        return self::SUCCESS;
    }
}
