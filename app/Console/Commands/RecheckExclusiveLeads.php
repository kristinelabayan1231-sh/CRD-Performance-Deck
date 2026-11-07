<?php

namespace App\Console\Commands;

use App\Models\Lead;
use App\Models\User;
use App\Services\ExclusiveLeads;
use App\Services\LeadGenerator;
use Illuminate\Console\Command;

class RecheckExclusiveLeads extends Command
{
    protected $signature = 'leads:recheck-exclusive
        {--apply : Move the leads. Without it, only reports what would move.}
        {--list : Name every lead that would move.}';

    protected $description = "Check the leads each exclusive CRA holds against the distribution rules, and move those that aren't theirs";

    public function handle(ExclusiveLeads $exclusive): int
    {
        $day = Lead::today();
        $names = LeadGenerator::cras()->mapWithKeys(fn (User $cra) => [$cra->id => $cra->displayName()]);

        foreach (LeadGenerator::cras()->filter(fn (User $cra) => $cra->isExclusiveOn($day)) as $cra) {
            $found = $exclusive->recheck($cra, $day);
            $this->info("{$cra->displayName()} on {$day->toDateString()}:");
            $this->line('  Another CRA owns the customer, untouched: '.$found[ExclusiveLeads::OWNED_UNTOUCHED]->count());
            $this->line('  Another CRA owns the customer, already contacted: '.$found[ExclusiveLeads::OWNED_CONTACTED]->count());
            $this->line('  CRD on the current list without the criteria: '.$found[ExclusiveLeads::CRD_ON_LIST]->count());
            $this->line('  Older CRD without the criteria, never moved here: '.$found[ExclusiveLeads::CRD_OLDER]->count());

            if ($this->option('list')) {
                foreach ([ExclusiveLeads::OWNED_UNTOUCHED, ExclusiveLeads::OWNED_CONTACTED, ExclusiveLeads::CRD_ON_LIST] as $group) {
                    foreach ($found[$group] as ['lead' => $lead, 'to' => $to]) {
                        $this->line("  {$group} | {$lead->id} | {$lead->customer_name} | {$lead->product_name} | ".($names[$to] ?? 'Unassigned'));
                    }
                }
            }

            if ($this->option('apply')) {
                $this->info('  Moved '.$exclusive->applyRecheck($cra, $day, $found).' leads.');
            }
        }

        if (! $this->option('apply')) {
            $this->comment('Nothing was moved. Run again with --apply to move them.');
        }

        return self::SUCCESS;
    }
}
