<?php

namespace App\Services;

use App\Models\PancakeEngagement;
use App\Models\PancakeOrder;
use App\Models\PancakePage;
use App\Models\User;
use App\Support\PancakeAccounts;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * The CRAs' orders on a day per seller and product page (Settings → Pancake Pages), deleted left out, for PC Report and
 * the Daily I.S. counted = not canceled (PC Report's # of Orders counts those); status is the order's status now.
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
     * @return array{orders: Collection<int, array{id: int, seller: string, page: string, sales: float, status: ?int, counted: bool}>, missingPage: bool}
     */
    public function forDay(Collection $cras, array $pages, CarbonImmutable $day): array
    {
        $sellers = PancakeAccounts::craBySeller($cras)->keys();
        if ($sellers->isEmpty() || $pages === []) {
            return ['orders' => collect(), 'missingPage' => false];
        }

        $normalize = fn (?string $name) => Str::lower(Str::squish((string) $name));
        $byName = collect($pages)->keyBy($normalize);
        $byId = PancakePage::whereNotNull('page_id')->get(['name', 'page_id'])
            ->filter(fn (PancakePage $page) => $byName->has($normalize($page->name)))
            ->mapWithKeys(fn (PancakePage $page) => [(string) $page->page_id => $byName[$normalize($page->name)]]);

        $orders = PancakeOrder::crdProducts()
            ->where(fn ($q) => $q->whereNull('status')->orWhere('status', '!=', CraPerformance::DELETED))
            ->whereDate('ordered_on', $day)
            ->whereIn('seller_name', $sellers)
            ->get(['id', 'seller_name', 'page_id', 'page_name', 'status', 'total_price', 'shecom_sales']);

        return [
            'orders' => $orders
                ->map(fn (PancakeOrder $order) => [
                    'seller' => PancakeEngagement::staffKey($order->seller_name),
                    'page' => $byId->get((string) $order->page_id) ?? ($byId->contains($byName->get($normalize($order->page_name))) ? null : $byName->get($normalize($order->page_name))),
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
}
