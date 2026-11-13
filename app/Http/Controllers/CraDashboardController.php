<?php

namespace App\Http\Controllers;

use App\Models\CustomerAccount;
use App\Models\ExclusiveListLead;
use App\Models\ExclusiveReservedLead;
use App\Models\Lead;
use App\Models\PancakeOrder;
use App\Models\Role;
use App\Models\User;
use App\Services\CustomerReassignment;
use App\Services\DailyIncomeStatement;
use App\Services\ExclusiveLeads;
use App\Services\HighValueCustomers;
use App\Services\LeadConflicts;
use App\Services\LeadGenerator;
use App\Services\PancakeClient;
use App\Services\SalesGoalProgress;
use App\Support\DashboardRange;
use App\Support\WorkingDate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;

/**
 * CRA Dashboard, a CRA's home page: today's pending leads (linking to their tracker), running sales against the daily
 * goal, net income, their High AOV and VIP customers, and the customers up for reassignment they can claim
 * (CustomerReassignment). A supervisor can open any CRA's, without claiming, and sees the customers whose CRA
 * assignments disagree (LeadConflicts).
 */
class CraDashboardController extends Controller
{
    private const PER_PAGE = 25;

    public function index(Request $request, CustomerReassignment $reassignment, SalesGoalProgress $goals, DailyIncomeStatement $statement, HighValueCustomers $highValue, ExclusiveLeads $exclusive, LeadConflicts $conflicts): View
    {
        $user = $request->user();
        $filters = $request->validate([
            'cra' => ['nullable', 'integer'],
            'q' => ['nullable', 'string', 'max:100'],
            'show' => ['nullable', 'in:all,high_aov,vip'],
        ]);
        $cras = LeadGenerator::cras();
        $isCra = $user->role?->slug === Role::CRA;
        $canViewAll = $user->can('segmentation.view_all');
        abort_unless($isCra || $canViewAll, 403);

        $cra = $isCra && ! isset($filters['cra']) ? $user : ($canViewAll ? $cras->firstWhere('id', (int) ($filters['cra'] ?? 0)) ?? $cras->first() : $user);
        abort_if($cra === null, 404, 'No active CRA yet.');
        abort_unless($cra->id === $user->id || $canViewAll, 403);

        $realToday = WorkingDate::realToday();
        $leadDay = Lead::today();
        $isExclusive = $cra->isExclusiveOn($leadDay);
        $todayLeads = $isExclusive ? $exclusive->leadsFor($cra, $leadDay) : Lead::where('assigned_to', $cra->id)->whereDate('listed_on', $leadDay);
        $pending = [
            'total' => (clone $todayLeads)->count(),
            'unprocessed' => (clone $todayLeads)->unprocessed()->count(),
            'url' => $isExclusive ? route('exclusive-leads.index') : route('segmentation.index'),
            'tracker' => $isExclusive ? 'Exclusive Lead Tracker' : 'Segmentation Tracker',
            'lead_day' => $leadDay,
        ];

        $todayRange = DashboardRange::fromFilters(['from' => $realToday->toDateString(), 'to' => $realToday->toDateString()], $realToday);
        $monthRange = DashboardRange::fromFilters([], $realToday);
        $today = $goals->for(collect([$cra]), $todayRange)['cras']->first();
        $month = $goals->for(collect([$cra]), $monthRange)['cras']->first();
        $sales = [
            'today' => $today['sales'],
            'daily_goal' => $today['goal'],
            'left_today' => max(0.0, $today['goal'] - $today['sales']),
            'progress' => $today['progress'],
            'month' => $month['sales'],
            'month_goal' => $month['goal'],
            'month_progress' => $month['progress'],
        ];

        // A few minutes is fresh enough: the Daily I.S. builds every day of the month for it.
        $netIncome = Cache::remember("cra-dashboard.net-income.{$cra->id}.{$realToday->toDateString()}", now()->addMinutes(5), fn () => [
            'today' => (float) ($statement->netIncome(collect([$cra]), $realToday, $realToday)[$cra->id] ?? 0),
            'month' => (float) ($statement->netIncome(collect([$cra]), $realToday->startOfMonth(), $realToday)[$cra->id] ?? 0),
        ]);

        $listed = $highValue->all();
        $mine = CustomerAccount::where('assigned_cra_id', $cra->id)->pluck('phone_key')
            ->map(fn ($key) => $listed[(string) $key] ?? null)->filter();
        $highValueCounts = ['high_aov' => $mine->where('high_aov', true)->count(), 'vip' => $mine->where('vip', true)->count()];

        $show = $filters['show'] ?? 'all';
        $claimable = $reassignment->claimableFor($cra, $filters['q'] ?? null, $show);
        $page = LengthAwarePaginator::resolveCurrentPage();
        $customers = new LengthAwarePaginator($claimable->forPage($page, self::PER_PAGE)->values(), $claimable->count(), self::PER_PAGE, $page, [
            'path' => $request->url(),
            'query' => $request->query(),
        ]);

        return view('cra-dashboard.index', [
            // Supervisors only: customers whose CRAs disagree, so who gets their lead needs a look.
            'conflicts' => $canViewAll && ! $isCra ? $conflicts->all() : [],
            'canAssign' => ! $isCra && $user->can('segmentation.transfer'),
            'assignCras' => ! $isCra && $user->can('segmentation.transfer') ? LeadGenerator::segmentationCras() : collect(),
            'cra' => $cra,
            'cras' => $canViewAll ? $cras : collect(),
            'canClaim' => $cra->id === $user->id && $isCra,
            'filters' => ['q' => $filters['q'] ?? null, 'show' => $show, 'cra' => $filters['cra'] ?? null],
            'pending' => $pending,
            'sales' => $sales,
            'netIncome' => $netIncome,
            'highValue' => $highValueCounts,
            'customers' => $customers,
            'craNames' => $cras->mapWithKeys(fn (User $other) => [$other->id => $other->displayName()]),
            'atRisk' => $reassignment->atRiskFor($cra),
            'afterDays' => CustomerReassignment::afterDays(),
            'firstOpening' => CustomerReassignment::startsOn()->addDays(CustomerReassignment::afterDays()),
            'realToday' => $realToday,
        ]);
    }

    /**
     * Check who gets these leads → Assign: a supervisor gives the customer's open lead to a CRA (the rules' CRA by
     * default), or keeps it with the CRA who has it. Either way the lead leaves the list.
     */
    public function assign(Request $request, Lead $lead, LeadConflicts $conflicts, PancakeClient $pancake): RedirectResponse
    {
        $user = $request->user();
        abort_if($user->role?->slug === Role::CRA, 403, 'Only supervisors can assign these leads.');
        $cras = LeadGenerator::segmentationCras();
        $data = $request->validate(['to' => ['required', 'integer', Rule::in($cras->pluck('id'))]], [
            'to.required' => 'Choose a CRA to assign the lead to.',
            'to.in' => 'Leads can only be assigned to an active Segmentation Tracker CRA.',
        ]);

        $isOpen = Lead::whereKey($lead->id)->unprocessed()->notConverted()->withoutPosSales()->exists();
        if (! $isOpen) {
            $conflicts->forget($lead->id);

            return back()->withErrors(['assign' => "{$lead->customer_name}'s lead was already worked or sold, so it stays where it is."]);
        }

        $to = $cras->firstWhere('id', (int) $data['to']);
        $isKept = $lead->assigned_to === $to->id;

        $isUntagged = false;
        if (! $isKept && ! $to->isExclusiveOn(Lead::today()) && $this->hasExclusiveTag($lead)) {
            try {
                $isUntagged = $this->removeExclusiveTag($lead, $user, $pancake);
            } catch (RuntimeException $e) {
                return back()->withErrors(['assign' => "{$lead->customer_name}'s lead stays where it is: the exclusive tag couldn't come off its order. {$e->getMessage()}"]);
            }
        }

        DB::transaction(function () use ($lead, $to, $user, $isKept) {
            if (! $isKept) {
                // From today on, like a backlog transfer; a lead not due yet keeps its day.
                $today = Lead::today();
                $lead->transferTo($to, $user, $lead->listed_on->greaterThan($today) ? $lead->listed_on : $today);
            }
            $lead->forceFill(['assignment_checked_at' => now(), 'assignment_checked_by' => $user->id])->save();
        });
        $conflicts->forget($lead->id);

        $name = $lead->customer_name ?: 'The customer';

        return back()->with('status', $isKept ? "{$name}'s lead stays with {$to->displayName()}." : "{$name}'s lead is now {$to->displayName()}'s."
            .($isUntagged ? ' The '.config('exclusive_leads.order_tag.name').' tag was taken off its order.' : ''));
    }

    /**
     * Whether the lead's own order may carry the exclusive POS tag: it does in the synced orders, or the lead is on the
     * Exclusive Lead Tracker (a tag added in Pancake shows up here only after the next sync).
     */
    private function hasExclusiveTag(Lead $lead): bool
    {
        return PancakeOrder::where('pancake_order_id', $this->orderNumber($lead))->whereJsonContains('tags', (int) config('exclusive_leads.order_tag.id'))->exists()
            || $lead->assignee?->exclusive_from !== null
            || ExclusiveListLead::where('lead_id', $lead->id)->exists()
            || ExclusiveReservedLead::where('lead_id', $lead->id)->exists();
    }

    /**
     * Take the exclusive POS tag off the lead's own order in Pancake (nothing is written when it hasn't got it).
     * True when the tag came off.
     *
     * @throws RuntimeException when Pancake can't be reached or the change doesn't save cleanly
     */
    private function removeExclusiveTag(Lead $lead, User $user, PancakeClient $pancake): bool
    {
        $orderNumber = $this->orderNumber($lead);

        try {
            $tags = $pancake->removeOrderTag($orderNumber, (int) config('exclusive_leads.order_tag.id'));
        } catch (RuntimeException $e) {
            Log::warning('Exclusive tag not removed', ['lead_id' => $lead->id, 'order' => $orderNumber, 'user_id' => $user->id, 'message' => $e->getMessage()]);

            throw $e;
        }

        if ($tags['before'] !== $tags['after']) {
            Log::info('Exclusive tag removed', ['lead_id' => $lead->id, 'order' => $orderNumber, 'user_id' => $user->id, 'tags_before' => $tags['before'], 'tags_after' => $tags['after']]);
        }

        PancakeOrder::where('pancake_order_id', $orderNumber)->first()?->update([
            'tags' => $tags['after'],
            'conversion_type' => PancakeOrder::conversionTypeFor($tags['after']),
        ]);

        return $tags['before'] !== $tags['after'];
    }

    private function orderNumber(Lead $lead): string
    {
        return Str::after($lead->order_id, Lead::POS_PREFIX);
    }

    /**
     * Claim a customer up for reassignment: they and their uncontacted leads become the CRA's.
     */
    public function claim(Request $request, CustomerReassignment $reassignment): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->role?->slug === Role::CRA && LeadGenerator::cras()->contains('id', $user->id), 403, 'Only CRAs can claim customers.');
        $data = $request->validate(['phone_key' => ['required', 'string', 'regex:/^[0-9]{6,15}$/'], 'name' => ['nullable', 'string', 'max:255']]);

        try {
            $moved = $reassignment->claim($user, $data['phone_key']);
        } catch (RuntimeException $e) {
            return back()->withErrors(['claim' => $e->getMessage()]);
        }

        $name = $data['name'] ?: 'The customer';

        return back()->with('status', "{$name} is now yours".($moved ? " · {$moved} ".str('lead')->plural($moved).' moved to your list today.' : '.'));
    }
}
