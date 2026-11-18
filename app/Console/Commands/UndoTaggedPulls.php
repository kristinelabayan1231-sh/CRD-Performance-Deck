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
        {--apply : Move the leads back. Without it, only reports what would move.}
        {--list : Name every lead.}';

    protected $description = 'Send back the leads the exclusive tag sync gave the exclusive CRA because of tags from before it (old tags): to the CRA who had them, or the pool';

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
        $untouched = Lead::whereIn('id', $leads->keys()->all())->untouched()->whereNull('contact_time')->pluck('id')->flip();
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
                // The old tag stays on the order, as before: no Pancake write.
                ExclusiveLeadTag::where('lead_id', $lead->id)->delete();
            }));
        }

        $this->info("{$cra->displayName()}: {$leads->count()} leads given by old tags. Back to the CRA who had them: {$plan['back']}, back to the pool: {$plan['pool']}, worked or moved since (stay): {$plan['kept']}.");
        $this->comment($this->option('apply') ? 'Done. No Pancake order was changed.' : 'Nothing was moved. Run again with --apply to move them.');

        return self::SUCCESS;
    }
}
