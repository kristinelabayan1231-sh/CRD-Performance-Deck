<?php

namespace App\Console\Commands;

use App\Models\Lead;
use App\Services\ExclusiveLeads;
use App\Services\ExclusiveLeadTagging;
use Illuminate\Console\Command;

class SyncExclusiveTags extends Command
{
    protected $signature = 'leads:sync-exclusive-tags
        {--limit=40 : Most orders to tag, and most to untag, in Pancake this run}
        {--dry-run : Only report what would change: no lead moves and no Pancake writes}
        {--list : With --dry-run, name every lead that would move}';

    protected $description = 'Keep the exclusive CRA\'s leads and the CRD - ANNA (EXCLUSIVE LEADS) POS order tag together';

    public function handle(ExclusiveLeadTagging $tagging, ExclusiveLeads $exclusive): int
    {
        $day = Lead::today();
        $cra = $tagging->owner($day);

        if ($cra === null) {
            $this->info('No exclusive CRA today.');

            return self::SUCCESS;
        }

        $name = $cra->displayName();
        $limit = max(0, (int) $this->option('limit'));
        $takenOff = $tagging->takenOffInPos($cra);
        $tagged = $tagging->newlyTagged($cra, $day);

        if ($this->option('dry-run')) {
            $this->info("{$name} on {$day->toDateString()}:");
            $this->line("  Tag missing on the synced order, checked in Pancake before leaving her: {$takenOff->count()}");
            $this->line("  Tagged in POS, would join her list: {$tagged->count()}");
            $this->line('  Her month\'s leads whose order still needs the tag: '.$tagging->untaggedLeads($cra)->count());

            if ($this->option('list')) {
                foreach ($takenOff as $lead) {
                    $this->line("  leave | {$lead->id} | {$lead->customer_name} | #".ExclusiveLeadTagging::orderNumber($lead));
                }
                foreach ($tagged as $lead) {
                    $this->line("  join | {$lead->id} | {$lead->customer_name} | #".ExclusiveLeadTagging::orderNumber($lead).' | from '.($lead->assignee?->displayName() ?? 'the pool'));
                }
            }
            $this->comment('Nothing was changed.');

            return self::SUCCESS;
        }

        $left = $tagging->applyTakenOff($cra, $day, $takenOff);
        $joined = $exclusive->addTagged($cra, $day, $tagged);
        $untag = $tagging->untagLeft($cra, $limit);
        $tag = $tagging->tagNext($cra, $limit);

        $this->info("{$name}: {$left['removed']} taken off (tag removed in POS), {$left['kept']} already worked kept, {$joined} tagged leads added.");
        $this->info("Tags: {$tag['already']} already on, {$tag['tagged']} added, {$tag['failed']} failed; {$untag['untagged']} taken off leads that left her, {$untag['failed']} failed.");

        return self::SUCCESS;
    }
}
