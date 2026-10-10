<?php

namespace App\Services;

use App\Models\DeliveredOrder;
use App\Models\Lead;
use App\Models\LogisticsOrder;
use App\Support\DashboardRange;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The customers behind the dashboard's churn rate (Customer Database → Churn): who was due to
 * reorder in the dates picked, who came back in time and who was lost, per list (overall, CRD or
 * FSD). Each page adds the customer's name, contact number, products ordered, total spent and,
 * for lost customers, what the CRA last recorded on their Segmentation Tracker lead.
 */
class ChurnCustomerList
{
    public const STATUSES = [
        'due' => 'Due to reorder',
        'back' => 'Came back in time',
        'lost' => 'Lost',
    ];

    public const LISTS = [
        'all' => 'Overall',
        'crd' => 'CRD',
        'fsd' => 'FSD',
    ];

    public function __construct(private CustomerChurn $churn) {}

    /**
     * Customers due, came back and lost, for the tiles.
     *
     * @return array{due: int, back: int, lost: int}
     */
    public function counts(DashboardRange $range, string $list): array
    {
        $rows = $this->rows($range, $list);
        $back = $rows->whereNotNull('back')->count();

        return ['due' => $rows->count(), 'back' => $back, 'lost' => $rows->count() - $back];
    }

    /**
     * One page of the customers in $status, soonest reorder deadline first.
     */
    public function page(DashboardRange $range, string $list, string $status, int $perPage = 50): LengthAwarePaginator
    {
        $rows = $this->rows($range, $list);
        $rows = match ($status) {
            'back' => $rows->whereNotNull('back'),
            'lost' => $rows->whereNull('back'),
            default => $rows,
        };
        $page = Paginator::resolveCurrentPage();

        return (new LengthAwarePaginator($this->withDetails($rows->forPage($page, $perPage)->values()), $rows->count(), $perPage, $page,
            ['path' => Paginator::resolveCurrentPath()]))->withQueryString();
    }

    /**
     * Every customer of the list for the range, kept as long as the Customer Database's results.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function rows(DashboardRange $range, string $list): Collection
    {
        return collect(Cache::remember(
            CustomerDatabase::cacheKey('churn', [$range->from->toDateString(), $range->to->toDateString(), $list]),
            now()->addMinutes(CustomerDatabase::CACHE_MINUTES),
            fn () => $this->churn->customers($range->from, $range->to, $list),
        ));
    }

    /**
     * Name, contact number, products ordered, total spent (their delivered orders' Pancake totals,
     * as in the Customer Database) and, for lost customers, their latest Segmentation Tracker lead.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    private function withDetails(Collection $rows): Collection
    {
        $phones = $rows->pluck('phone_key')->all();

        if ($phones === []) {
            return $rows;
        }

        $catalog = new ProductCatalog;
        $deliveries = LogisticsOrder::whereIn('phone_key', $phones)->orderBy('delivered_date')
            ->get(['phone_key', 'customer_name', 'phone_number', 'product'])->groupBy('phone_key');
        // Total spent: CRA-handled orders only, as in the Customer Database.
        $handled = app(CustomerDatabase::class)->handledSql();
        $spent = DB::table('logistics_orders as lo')
            ->join('pancake_orders as po', 'po.pancake_order_id', '=', 'lo.order_id')
            ->whereIn('lo.phone_key', $phones)
            ->whereRaw($handled['sql'], $handled['bindings'])
            ->whereRaw(CustomerDatabase::CRD_PRODUCTS)
            ->groupBy('lo.phone_key')
            ->selectRaw('lo.phone_key, sum(po.total_price) as spent')
            ->pluck('spent', 'phone_key');
        // The delivery judged, for a name and number when logistics has none for them yet.
        $judged = DeliveredOrder::whereIn('order_id', $rows->pluck('order_id')->all())->get(['order_id', 'customer_name', 'phone_number'])->keyBy('order_id');
        $leads = $this->latestLeads($rows);

        return $rows->map(function (array $row) use ($deliveries, $spent, $catalog, $leads, $judged) {
            $mine = $deliveries[$row['phone_key']] ?? collect();

            return [
                ...$row,
                'name' => $mine->last()?->customer_name ?: ($judged[$row['order_id']]->customer_name ?? null) ?: 'Unknown',
                'phone' => $mine->last()?->phone_number ?? $judged[$row['order_id']]->phone_number ?? $row['phone_key'],
                'products' => $mine->map(fn (LogisticsOrder $order) => $catalog->match((string) $order->product)?->name ?? $order->product)
                    ->push($row['product'])->filter()->unique()->values()->all(),
                'total_spent' => (float) ($spent[$row['phone_key']] ?? 0),
                'lead' => $leads[$row['phone_key']] ?? null,
            ];
        });
    }

    /**
     * Each customer's latest Segmentation Tracker lead from their judged delivery on: what the CRA
     * recorded (status, customer's feedback, tag, contact date, notes), or null if they had none.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, array{status: ?string, feedback: ?string, tag: ?string, contacted: ?string, notes: ?string}>
     */
    private function latestLeads(Collection $rows): array
    {
        $wanted = $rows->pluck('delivered', 'phone_key')->all();
        $statuses = config('segmentation.statuses');
        $feedback = config('segmentation.feedback');
        $tags = config('segmentation.customer_tags');
        $leads = [];

        Lead::whereDate('est_out_of_stock_date', '>=', min($wanted))
            ->orderBy('est_out_of_stock_date')->orderBy('id')
            ->toBase()->get(['phone_number', 'delivered_date', 'status', 'feedback', 'customer_tag', 'contact_date', 'notes'])
            ->each(function (object $lead) use ($wanted, $statuses, $feedback, $tags, &$leads) {
                $phone = LeadGenerator::normalizePhone((string) $lead->phone_number);

                if (! isset($wanted[$phone]) || substr((string) $lead->delivered_date, 0, 10) < $wanted[$phone]) {
                    return;
                }

                $leads[$phone] = [
                    'status' => $lead->status ? ($statuses[$lead->status][0] ?? $lead->status) : null,
                    'feedback' => $lead->feedback ? ($feedback[$lead->feedback][0] ?? $lead->feedback) : null,
                    'tag' => $lead->customer_tag ? ($tags[$lead->customer_tag][0] ?? $lead->customer_tag) : null,
                    'contacted' => $lead->contact_date ? substr((string) $lead->contact_date, 0, 10) : null,
                    'notes' => $lead->notes,
                ];
            });

        return $leads;
    }
}
