<?php

namespace App\Http\Controllers;

use App\Models\CustomerAccount;
use App\Models\Lead;
use App\Models\Role;
use App\Models\User;
use App\Services\CustomerReassignment;
use App\Services\DailyIncomeStatement;
use App\Services\ExclusiveLeads;
use App\Services\HighValueCustomers;
use App\Services\LeadGenerator;
use App\Services\SalesGoalProgress;
use App\Support\DashboardRange;
use App\Support\WorkingDate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;
use RuntimeException;

/**
 * CRA Dashboard, a CRA's home page: today's pending leads (linking to their tracker), running sales against the daily
 * goal, net income, their High AOV and VIP customers, and the customers up for reassignment they can claim
 * (CustomerReassignment). A supervisor can open any CRA's, without claiming.
 */
class CraDashboardController extends Controller
{
    private const PER_PAGE = 25;

    public function index(Request $request, CustomerReassignment $reassignment, SalesGoalProgress $goals, DailyIncomeStatement $statement, HighValueCustomers $highValue, ExclusiveLeads $exclusive): View
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
