<?php

namespace App\Services;

use App\Models\CustomerAccount;
use App\Models\CustomerLink;
use App\Models\ExclusiveListLead;
use App\Models\Lead;
use App\Models\User;
use App\Support\NicheRules;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Finds active CRAs' untouched leads that belong to the CRA who handled the most of the customer's orders (CustomerOwners),
 * and moves them there. A worked or noted lead stays. A saved High AOV / VIP CRA stays unless another CRA has strictly more
 * orders, and then the saved CRA moves with the lead. A lead whose rightful CRA is exclusive is only counted: that CRA's
 * list never takes a lead another CRA holds.
 */
class LeadOwnerCorrections
{
    public function __construct(private CustomerOwners $owners, private HighValueCustomers $highValue) {}

    /**
     * @param  list<int>  $forcedLeadIds  move these to their rightful CRA even if worked
     * @return array{moves: Collection<int, array{lead: Lead, to: int, account: ?string}>, held_for_exclusive: int}
     */
    public function scan(array $forcedLeadIds = []): array
    {
        $day = Lead::today();
        $cras = LeadGenerator::cras()->pluck('id');
        $leads = Lead::whereIn('assigned_to', $cras)->withoutPosSales()->orderBy('id')->get();
        $untouched = Lead::whereIn('assigned_to', $cras)->untouched()->pluck('id')->flip();
        $forced = array_flip($forcedLeadIds);
        $phoneOf = fn (Lead $lead) => LeadGenerator::normalizePhone((string) $lead->phone_number);
        $phones = $leads->map($phoneOf)->filter()->unique()->values()->all();
        $owners = app(LeadGenerator::class)->ownersFor($phones, $this->highValue);
        $counts = $this->owners->orderCounts($phones);
        $niches = NicheRules::current();
        $exclusive = User::exclusiveOn($day)->pluck('id')->flip();
        // A loop, not flatMap: numeric phone keys would be renumbered.
        $primaryOf = [];
        foreach (array_chunk($phones, 1000) as $chunk) {
            $primaryOf += CustomerLink::whereIn('phone_key', $chunk)->pluck('primary_phone_key', 'phone_key')->all();
        }
        $moves = collect();
        $heldForExclusive = 0;

        foreach ($leads as $lead) {
            $phone = $phoneOf($lead);
            $holder = $lead->assigned_to;
            $rightful = $owners['handled'][$phone] ?? null;
            $saved = $owners['high_value'][$phone] ?? null;

            if ($phone === '' || $rightful === null || $rightful === $holder) {
                continue;
            }

            $isSavedHolders = $saved === $holder;

            if (! isset($forced[$lead->id])) {
                $beatsSaved = ($counts[$phone][$rightful] ?? 0) > ($counts[$phone][$holder] ?? 0);
                $isLeftAlone = ! isset($untouched[$lead->id])
                    || ! $niches->allows($rightful, $lead->product_name)
                    || ($saved !== null && ! $isSavedHolders && $saved !== $rightful)
                    || ($isSavedHolders && ! $beatsSaved);

                if ($isLeftAlone) {
                    continue;
                }

                if (isset($exclusive[$rightful])) {
                    $heldForExclusive++;

                    continue;
                }
            }

            $moves->push(['lead' => $lead, 'to' => $rightful, 'account' => $isSavedHolders ? (string) ($primaryOf[$phone] ?? $phone) : null]);
        }

        return ['moves' => $moves, 'held_for_exclusive' => $heldForExclusive];
    }

    /**
     * Move the leads scan() found, each in its own transaction, skipping any that changed hands since.
     *
     * @param  Collection<int, array{lead: Lead, to: int, account: ?string}>  $moves
     * @return int leads moved
     */
    public function apply(Collection $moves): int
    {
        $day = Lead::today();
        $cras = LeadGenerator::cras()->keyBy('id');
        $moved = 0;

        foreach ($moves as ['lead' => $lead, 'to' => $to, 'account' => $account]) {
            DB::transaction(function () use ($lead, $to, $account, $day, $cras, &$moved) {
                $current = Lead::lockForUpdate()->find($lead->id);

                if ($current?->assigned_to !== $lead->assigned_to || ! $cras->has($to)) {
                    return;
                }

                if ($account !== null) {
                    CustomerAccount::where('phone_key', $account)->where('assigned_cra_id', $lead->assigned_to)
                        ->update(['assigned_cra_id' => $to, 'assigned_at' => now()]);
                }

                ExclusiveListLead::where('user_id', $current->assigned_to)->whereDate('list_date', $day)->where('lead_id', $current->id)->delete();
                $current->transferTo($cras[$to], null, $day);
                $moved++;
            });
        }

        return $moved;
    }
}
