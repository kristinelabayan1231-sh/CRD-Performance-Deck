<?php

namespace App\Services;

use App\Models\PancakeEngagement;
use App\Models\PancakeOrder;
use App\Models\PancakePage;
use App\Models\User;
use App\Support\PancakeAccounts;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * The CRAs' orders on a day per CRA and product page (Settings → Pancake Pages), deleted left out, for the Daily I.S.
 * counted = not canceled (PC Report's # of Orders counts those); status is the order's status now. As in the Conversion
 * Breakdown, an order the CVR Tracker counts for a CRA goes to that CRA, also when made under another account.
 *
 * Matched on the Pancake page ID, since an order carries the page's name as Pancake shows it, which can differ from
 * Settings (e.g. "Sinuxyl Nasal Care PH" for "Sinuxyl Nasal Care"); by name, ignoring case and spaces, only for a page
 * Settings has no ID for. Orders saved before pages were (before Oct 14) may have no page at all.
 */
class PageOrders
{
    /**
     * @param  Collection<int, User>  $cras
     * @param  list<string>  $pages
     * @return array{orders: Collection<int, array{id: int, cra: int, seller: string, page: string, sales: float, status: ?int, counted: bool}>, missingPage: bool}
     */
    public function forDay(Collection $cras, array $pages, CarbonImmutable $day): array
    {
        $accounts = PancakeAccounts::craBySeller($cras)->map(fn (User $cra) => $cra->id);
        if ($accounts->isEmpty() || $pages === []) {
            return ['orders' => collect(), 'missingPage' => false];
        }

        $pageOf = self::pageMatcher($pages);

        $orders = PancakeOrder::crdSales()
            ->leftJoin('cvr_order_entries as cvr', 'cvr.pancake_order_id', '=', 'pancake_orders.pancake_order_id')
            ->where(fn ($q) => $q->whereNull('pancake_orders.status')->orWhere('pancake_orders.status', '!=', CraPerformance::DELETED))
            ->whereDate('pancake_orders.ordered_on', $day)
            ->where(fn ($q) => $q
                ->where(fn ($q) => $q->whereNull('cvr.user_id')->whereIn('pancake_orders.seller_name', $accounts->keys()))
                ->orWhereIn('cvr.user_id', $cras->pluck('id')))
            ->get(['pancake_orders.id', 'pancake_orders.seller_name', 'pancake_orders.page_id', 'pancake_orders.page_name', 'pancake_orders.status',
                'pancake_orders.total_price', 'pancake_orders.shecom_sales', 'cvr.user_id as counted_for']);

        return [
            'orders' => $orders
                ->map(fn (PancakeOrder $order) => [
                    'cra' => $order->counted_for !== null ? (int) $order->counted_for : $accounts[$order->seller_name],
                    'seller' => PancakeEngagement::staffKey($order->seller_name),
                    'page' => $pageOf($order),
                    'sales' => $order->sales(),
                    'status' => $order->status,
                    'counted' => $order->isCounted(),
                    'id' => $order->id,
                ])
                ->filter(fn (array $order) => $order['page'] !== null)
                ->values(),
            'missingPage' => $orders->contains(fn (PancakeOrder $order) => $order->page_id === null && $order->page_name === null),
        ];
    }

    /**
     * Which of $pages an order is on: by its Pancake page ID, else by name for a page Settings has no ID for; null when none.
     *
     * @param  list<string>  $pages
     * @return Closure(PancakeOrder): ?string
     */
    public static function pageMatcher(array $pages): Closure
    {
        $normalize = fn (?string $name) => Str::lower(Str::squish((string) $name));
        $byName = collect($pages)->keyBy($normalize);
        $byId = PancakePage::whereNotNull('page_id')->get(['name', 'page_id'])
            ->filter(fn (PancakePage $page) => $byName->has($normalize($page->name)))
            ->mapWithKeys(fn (PancakePage $page) => [(string) $page->page_id => $byName[$normalize($page->name)]]);

        return fn (PancakeOrder $order) => $byId->get((string) $order->page_id)
            ?? ($byId->contains($byName->get($normalize($order->page_name))) ? null : $byName->get($normalize($order->page_name)));
    }
}
