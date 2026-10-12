<?php

namespace App\Services;

use App\Models\CustomerLink;
use App\Models\DeliveredOrder;
use App\Models\Lead;
use App\Models\LogisticsOrder;
use App\Models\PancakeOrder;
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

    public function __construct(private CustomerChurn $churn, private CustomerDatabase $customers) {}

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
     * Name, contact number, products ordered, total spent (all their delivered orders' Pancake totals,
     * FSD ones too) and, for lost customers, their latest Segmentation Tracker lead.
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
        // Total spent: every delivered order's Pancake total, FSD and CRA-handled alike (unlike the
        // Customer Database's CRA-only total), so an FSD customer's worth shows too.
        $spent = DB::table('logistics_orders as lo')
            ->join('pancake_orders as po', 'po.pancake_order_id', '=', 'lo.order_id')
            ->whereIn('lo.phone_key', $phones)
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
     * A churn customer's orders and deliveries, newest first, for the Churn list's order history pop-up:
     * every Pancake order (deleted ones left out) and every delivery of all their numbers, with who
     * handled it. counts = it would bring them back (a Pancake order not canceled with a listed product,
     * or a delivery with one).
     *
     * @return array{name: string, phone: string, orders: list<array{order_id: string, ordered: ?string, delivered: ?string, team: ?string, seller: ?string, by_cra: bool, status_label: string, status_classes: string, amount: ?float, items: string, counts: bool}>}|null
     */
    public function history(string $phoneKey): ?array
    {
        $members = CustomerLink::membersOf(CustomerLink::primaryFor($phoneKey));
        $deliveries = LogisticsOrder::whereIn('phone_key', $members)->orderByDesc('delivered_date')->get()->keyBy('order_id');
        $pos = PancakeOrder::whereIn('phone_key', $members)->where(fn ($q) => $q->whereNull('status')->orWhere('status', '!=', 7))
            ->get()->keyBy('pancake_order_id');

        if ($deliveries->isEmpty() && $pos->isEmpty()) {
            return null;
        }

        $statuses = config('customers.pos_statuses');
        $day = fn ($date) => $date ? substr((string) $date, 0, 10) : null;
        $orders = $pos->map(function (PancakeOrder $order) use ($deliveries, $statuses, $day) {
            $delivery = $deliveries->get($order->pancake_order_id);

            return [
                'order_id' => (string) $order->pancake_order_id,
                'ordered' => $day($order->ordered_on),
                'delivered' => $day($delivery?->delivered_date),
                'team' => $delivery?->team,
                'seller' => $order->seller_name ? ucwords(strtolower($order->seller_name)) : null,
                'by_cra' => $this->customers->handledByCra($delivery?->team, $order->seller_name),
                'status_label' => $statuses[$order->status][0] ?? ($order->status_name ?: 'Status '.($order->status ?? '—')),
                'status_classes' => $statuses[$order->status][1] ?? 'bg-canvas text-muted',
                'amount' => (float) $order->total_price,
                'items' => collect($order->items ?? [])->pluck('name')->filter()->join(', '),
                'counts' => ! $order->non_crd && ! in_array($order->status, PancakeOrder::NOT_COUNTED_STATUSES, true),
            ];
        });

        // Deliveries Pancake has no copy of.
        foreach ($deliveries->reject(fn (LogisticsOrder $order) => $pos->has($order->order_id)) as $order) {
            $orders[$order->order_id] = [
                'order_id' => (string) $order->order_id,
                'ordered' => null,
                'delivered' => $day($order->delivered_date),
                'team' => $order->team,
                'seller' => null,
                'by_cra' => $order->team === LogisticsOrder::TEAM_CRD,
                'status_label' => $statuses[3][0],
                'status_classes' => $statuses[3][1],
                'amount' => null,
                'items' => (string) $order->product,
                'counts' => ! $order->non_crd,
            ];
        }

        $latest = $deliveries->first() ?? $pos->sortByDesc('ordered_on')->first();
        $numbers = $deliveries->pluck('phone_number', 'phone_key')->all();

        return [
            'name' => $latest->customer_name ?: 'Unknown',
            'phone' => implode(' / ', array_map(fn (string $phone) => $numbers[$phone] ?? $phone, $members)),
            'orders' => $orders->sortByDesc(fn (array $o) => $o['ordered'] ?? $o['delivered'])->values()->all(),
        ];
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
