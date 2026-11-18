<?php

namespace App\Console\Commands;

use App\Models\BacklogHandout;
use App\Models\ExclusiveLeadTag;
use App\Models\ExclusiveListLead;
use App\Models\ExclusiveOldTag;
use App\Models\Lead;
use App\Models\LeadMove;
use App\Models\LeadTransfer;
use App\Services\ExclusiveLeads;
use App\Services\ExclusiveLeadTagging;
use App\Services\LeadGenerator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class UndoTaggedPulls extends Command
{
    protected $signature = 'leads:undo-tagged-pulls
        {--apply : Move the leads back and take the tag off their orders in Pancake. Without it, only reports.}
        {--list : Name every lead.}';

    protected $description = 'Send back the leads the exclusive tag sync gave the exclusive CRA because of tags from before it (old tags), to the CRA who had them or the pool, and take the tag off their orders';

    public function handle(ExclusiveLeadTagging $tagging): int
    {
        $cra = $tagging->owner(Lead::today());
        if ($cra === null) {
            $this->info('No exclusive CRA today.');

            return self::SUCCESS;
        }

        $entries = ExclusiveListLead::where('user_id', $cra->id)->where('source', ExclusiveLeads::TAGGED)
            ->whereIn('lead_id', Lead::whereIn('order_id', ExclusiveOldTag::select('pancake_order_id'))->select('id'))
            ->get()->groupBy('lead_id');
        $leads = Lead::whereIn('id', $entries->keys()->all())->get()->keyBy('id');
        // Not contacted: no status, date or time of contact. A tag or note alone isn't contact.
        $untouched = Lead::whereIn('id', $leads->keys()->all())->unprocessed()->whereNull('contact_time')->pluck('id')->flip();
        $active = LeadGenerator::cras()->keyBy('id');
        $plan = ['back' => 0, 'pool' => 0, 'kept' => 0];

        foreach ($leads as $lead) {
            $isHers = $lead->assigned_to === $cra->id && isset($untouched[$lead->id]);
            $transfer = LeadTransfer::where('lead_id', $lead->id)->where('to_user_id', $cra->id)->whereNull('transferred_by')->latest('id')->first();
            $to = $transfer && $active->has($transfer->from_user_id) ? $active[$transfer->from_user_id] : null;
            $outcome = ! $isHers ? 'kept' : ($to ? 'back' : 'pool');
            $plan[$outcome]++;

            if ($this->option('list')) {
                $this->line("  {$outcome} | {$lead->id} | {$lead->customer_name} | #{$lead->order_id} | ".($outcome === 'back' ? $to->displayName() : ($outcome === 'pool' ? 'pool' : 'worked or moved since: stays')));
            }

            if (! $this->option('apply') || $outcome === 'kept') {
                continue;
            }

            Cache::lock("exclusive-leads-{$cra->id}", 300)->block(60, fn () => DB::transaction(function () use ($lead, $cra, $to, $transfer, $entries) {
                // The pull's own records: made from when it put the lead on her list.
                $since = $entries[$lead->id]->min('created_at')->subMinute();

                if ($to !== null) {
                    $move = LeadMove::where('lead_id', $lead->id)->where('assigned_to', $to->id)->where('created_at', '>=', $since)->latest('id')->first();
                    $lead->forceFill(['assigned_to' => $to->id, 'listed_on' => $move?->listed_on ?? $lead->listed_on])->save();
                    $move?->delete();
                    $transfer->delete();
                } else {
                    $lead->forceFill(['assigned_to' => null, 'assigned_at' => null, 'listed_on' => $lead->est_out_of_stock_date])->save();
                }

                BacklogHandout::where('lead_id', $lead->id)->where('user_id', $cra->id)->where('created_at', '>=', $since)->delete();
                ExclusiveListLead::whereIn('id', $entries[$lead->id]->pluck('id')->all())->delete();
                ExclusiveLeadTag::where('lead_id', $lead->id)->delete();
            }));
        }

        $this->info("{$cra->displayName()}: {$leads->count()} leads given by old tags. Back to the CRA who had them: {$plan['back']}, back to the pool: {$plan['pool']}, worked or moved since (stay): {$plan['kept']}.");

        // Every lead sent back (now and by an earlier run) whose order still carries her tag.
        $stillTagged = $tagging->sentBackStillTagged($cra);
        if (! $this->option('apply')) {
            $this->info("Leads sent back whose order still has the tag (comes off in Pancake): {$stillTagged->count()}.");
            if ($this->option('list')) {
                foreach ($stillTagged as $lead) {
                    $this->line("  untag | {$lead->id} | {$lead->customer_name} | #".ExclusiveLeadTagging::orderNumber($lead));
                }
            }
            $this->comment('Nothing was changed. Run again with --apply to move them and take the tags off.');

            return self::SUCCESS;
        }

        $untag = $tagging->untag($stillTagged);
        $this->info("Tag taken off {$untag['untagged']} orders in Pancake.");
        foreach ($untag['failed'] as $failure) {
            $this->warn("  Not untagged {$failure}");
        }
        $this->comment('Done.');

        return self::SUCCESS;
    }
}
