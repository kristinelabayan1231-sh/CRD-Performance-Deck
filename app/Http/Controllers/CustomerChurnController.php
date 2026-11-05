<?php

namespace App\Http\Controllers;

use App\Services\ChurnCustomerList;
use App\Support\DashboardRange;
use App\Support\WorkingDate;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CustomerChurnController extends Controller
{
    /**
     * Customer Database → Churn: the customers behind the dashboard's churn rate, for the same month or dates.
     */
    public function index(Request $request, ChurnCustomerList $churn): View
    {
        $today = WorkingDate::realToday();
        $filters = $request->validate([
            'month' => ['nullable', 'date_format:Y-m', 'before_or_equal:'.$today->format('Y-m')],
            'from' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:'.$today->toDateString()],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'status' => ['nullable', Rule::in(array_keys(ChurnCustomerList::STATUSES))],
            'list' => ['nullable', Rule::in(array_keys(ChurnCustomerList::LISTS))],
        ], [
            'to.after_or_equal' => 'The "to" date must be on or after the "from" date.',
        ]);
        $range = DashboardRange::fromFilters($filters, $today);
        $status = $filters['status'] ?? 'due';
        $list = $filters['list'] ?? 'all';

        return view('customers.churn', [
            'customers' => $churn->page($range, $list, $status),
            'counts' => $churn->counts($range, $list),
            'filters' => [...$filters, 'status' => $status, 'list' => $list],
            'range' => $range,
            'today' => $today,
            'graceDays' => (int) config('customers.churn_grace_days'),
        ]);
    }

    /**
     * The churn list's order history pop-up (information only): every order and delivery of the
     * customer, with the reorder window of the delivery judged picked out.
     */
    public function history(Request $request, ChurnCustomerList $churn, string $phoneKey): View
    {
        $window = $request->validate([
            'order_id' => ['required', 'string', 'max:64'],
            'team' => ['required', Rule::in(['crd', 'fsd'])],
            'delivered' => ['required', 'date_format:Y-m-d'],
            'ran_out' => ['required', 'date_format:Y-m-d'],
            'deadline' => ['required', 'date_format:Y-m-d'],
        ]);
        $history = $churn->history($phoneKey);

        abort_if($history === null, 404);

        return view('customers._churn-history', ['customer' => $history, 'window' => $window]);
    }
}
