<?php

namespace App\Services;

use App\Models\FacebookPage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class PancakeService
{
    protected string $baseV1 = 'https://pages.fm/api/public_api/v1';
    protected string $baseV2 = 'https://pages.fm/api/public_api/v2';
    protected string $basePos = 'https://pos.pages.fm/api/v1';

    /**
     * Conversation IDs (format "{page_id}_{thread_id}", matching the `id`
     * field on conversations from eachInboxConversation) that have at least
     * one order created on the given day whose *current* status is
     * "Delivered" (status code 3). Delivery can lag order creation by
     * several days, so this checks the live status as of the call — it is
     * a snapshot, not fixed at the order's creation time. Bounded to one
     * day at a time because the shop's full order history runs into the
     * millions; a one-day inserted_at window keeps each call to a few
     * hundred rows.
     *
     * @return array<string, true> Set (keyed by conversation_id) for O(1) lookup.
     */
    public function deliveredConversationIds(string $shopId, string $apiKey, string $date): array
    {
        $start = Carbon::parse($date)->startOfDay()->utc()->timestamp;
        $end = Carbon::parse($date)->endOfDay()->utc()->timestamp;

        $ids = [];
        $pageNumber = 1;

        do {
            // Pancake's POS API 500s on the standard filter_status[0]=3 array
            // encoding Laravel/Guzzle produce — it requires the bare
            // filter_status[]=3 form, so the query string is built by hand.
            $query = http_build_query([
                'api_key' => $apiKey,
                'page_size' => 200,
                'page_number' => $pageNumber,
                'updateStatus' => 'inserted_at',
                'startDateTime' => $start,
                'endDateTime' => $end,
            ]) . '&filter_status[]=3';

            $response = Http::retry(3, 300)->get("{$this->basePos}/shops/{$shopId}/orders?{$query}");

            $response->throw();
            $json = $response->json();
            $batch = $json['data'] ?? [];

            foreach ($batch as $order) {
                if (! empty($order['conversation_id'])) {
                    $ids[$order['conversation_id']] = true;
                }
            }

            $pageNumber++;
        } while (count($batch) === 200 && $pageNumber <= ($json['total_pages'] ?? $pageNumber));

        return $ids;
    }

    /**
     * Search/browse the shop's customer database (POS API). Returns the
     * raw paginated shape so the controller can drive pagination controls.
     */
    public function searchCustomers(string $shopId, string $apiKey, ?string $search, int $pageNumber = 1, int $pageSize = 25): array
    {
        $response = Http::retry(3, 300)->get("{$this->basePos}/shops/{$shopId}/customers", array_filter([
            'api_key' => $apiKey,
            'page_size' => $pageSize,
            'page_number' => $pageNumber,
            'search' => $search,
        ]));

        $response->throw();
        $json = $response->json();

        return [
            'data' => $json['data'] ?? [],
            'total_entries' => $json['total_entries'] ?? 0,
            'total_pages' => $json['total_pages'] ?? 1,
            'page_number' => $json['page_number'] ?? $pageNumber,
        ];
    }

    /**
     * Pancake's customers endpoint has no server-side sort by purchased
     * amount / order count (every option_sort value silently falls back to
     * its default order — confirmed against the live API), and the shop
     * has 1.2M+ customers, so a true global sort isn't feasible to compute
     * live. This instead pulls a bounded window of matches (parallelized
     * via Http::pool to keep wall time reasonable), sorts them in memory,
     * and paginates that in-memory list — an honest "sorted among the
     * first N matches" rather than a claim of a true global ranking.
     *
     * @return array{data: array, total_entries: int, total_pages: int, page_number: int, scanned: int, truncated: bool}
     */
    public function sortedCustomers(string $shopId, string $apiKey, ?string $search, string $sort, int $pageNumber = 1, int $pageSize = 25, int $maxScan = 2000): array
    {
        $scanPageSize = 200;
        $scanPages = (int) ceil($maxScan / $scanPageSize);

        $responses = Http::pool(fn ($pool) => collect(range(1, $scanPages))->map(
            fn ($p) => $pool->as((string) $p)->retry(3, 300)->get("{$this->basePos}/shops/{$shopId}/customers", array_filter([
                'api_key' => $apiKey,
                'page_size' => $scanPageSize,
                'page_number' => $p,
                'search' => $search,
            ]))
        )->all());

        $all = [];
        $totalEntries = 0;

        foreach (range(1, $scanPages) as $p) {
            $response = $responses[(string) $p];

            if ($response->failed()) {
                continue;
            }

            $json = $response->json();
            $totalEntries = max($totalEntries, $json['total_entries'] ?? 0);
            $batch = $json['data'] ?? [];
            $all = array_merge($all, $batch);

            if (count($batch) < $scanPageSize) {
                break;
            }
        }

        $sorted = (match ($sort) {
            'ltv_asc' => collect($all)->sortBy(fn ($c) => $c['purchased_amount'] ?? 0),
            'orders_desc' => collect($all)->sortByDesc(fn ($c) => $c['order_count'] ?? 0),
            default => collect($all)->sortByDesc(fn ($c) => $c['purchased_amount'] ?? 0),
        })->values();

        $scanned = $sorted->count();
        $truncated = $totalEntries > $scanned;

        return [
            'data' => $sorted->forPage($pageNumber, $pageSize)->values()->all(),
            'total_entries' => $totalEntries,
            'total_pages' => (int) max(1, ceil($scanned / $pageSize)),
            'page_number' => $pageNumber,
            'scanned' => $scanned,
            'truncated' => $truncated,
        ];
    }

    /**
     * The shop's CRD seller accounts (POS users whose name contains the
     * configured filter term, e.g. "CRD"), with name variants merged via
     * the same alias map the deck uses. One canonical seller can map to
     * several POS user ids (e.g. "CRD ANNA PACLIBARE" has three accounts),
     * so callers filter orders by the full id list and group by name.
     *
     * Cached for an hour — the roster changes rarely and the users
     * endpoint returns the full 200+ person shop staff list every call.
     * Returns [] when the endpoint is unavailable rather than failing the
     * page; callers treat an empty list as "no CRD scoping possible".
     *
     * @return array<int, array{id: string, name: string}>
     */
    public function crdSellers(string $shopId, string $apiKey): array
    {
        try {
            return Cache::remember("pancake:crd-sellers:{$shopId}", now()->addHour(), function () use ($shopId, $apiKey) {
                $response = Http::retry(3, 300)->get("{$this->basePos}/shops/{$shopId}/users", [
                    'api_key' => $apiKey,
                ]);
                $response->throw();

                $filterTerm = strtolower(config('pos_report.seller_filter'));
                $sellers = [];

                foreach ($response->json('data', []) as $entry) {
                    $user = $entry['user'] ?? [];
                    $name = $this->canonicalSellerName($user['name'] ?? null);

                    if ($name && ! empty($user['id']) && str_contains(strtolower($name), $filterTerm)) {
                        $sellers[] = ['id' => $user['id'], 'name' => $name];
                    }
                }

                return $sellers;
            });
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Same normalization the deck's PosReportService applies to CSV seller
     * names: collapse runs of whitespace, then merge known name variants
     * into their canonical form.
     */
    protected function canonicalSellerName(?string $name): ?string
    {
        $name = preg_replace('/\s+/', ' ', trim((string) $name));

        if ($name === '') {
            return null;
        }

        return config('pos_report.seller_aliases', [])[$name] ?? $name;
    }

    /**
     * Builds everything the Customers dashboard needs — the deduped set of
     * customers who ordered in [startDate, endDate] (any status, any page),
     * per-page and per-product activity, and a per-seller breakdown — by
     * streaming through orders in the range and reducing immediately,
     * rather than collecting full order objects. Order objects are heavy
     * (90+ fields including nested images/histories/notes); holding a few
     * thousand of them in memory at once blew the PHP memory limit in
     * testing, the same failure mode eachInboxConversation was already
     * rewritten to avoid. Pages are fetched in small concurrent chunks
     * (Http::pool) for speed, reduced, then discarded before the next
     * chunk starts, so peak memory stays bounded regardless of how wide
     * the date range is.
     *
     * When $sellerIds is given, orders are filtered server-side to those
     * assigning sellers (the "User" filter in the POS calendar UI) — the
     * orders endpoint honors bare `assigning_seller_id[]=` params, so the
     * query string is built by hand like deliveredConversationIds does.
     *
     * Everything is aggregated per canonical seller name so a subset of
     * sellers can be re-sliced later in PHP (sliceSummary) without another
     * API scan — that's what lets snapshots serve the seller picker.
     *
     * Only a light subset of each customer's fields is kept (not their
     * full record — addresses/notes/tags aren't needed here) to keep the
     * per-customer footprint small; the customer detail page fetches the
     * full record separately via getCustomer().
     *
     * This is also the *only* POS endpoint confirmed to support real
     * server-side date filtering — the customers endpoint silently ignores
     * every date-filter param name tried against it.
     *
     * @param  array<string, string>  $pageNames  page_id => page_name
     * @param  array<int, string>  $sellerIds  POS user ids to filter orders to
     */
    public function summarizeCustomerActivityInRange(string $shopId, string $apiKey, string $startDate, string $endDate, array $pageNames, int $maxOrders = 6000, array $sellerIds = []): array
    {
        $start = Carbon::parse($startDate)->startOfDay()->utc()->timestamp;
        $end = Carbon::parse($endDate)->endOfDay()->utc()->timestamp;
        $pageSize = 200;
        $chunkSize = 3;

        $baseQuery = [
            'api_key' => $apiKey,
            'page_size' => $pageSize,
            'updateStatus' => 'inserted_at',
            'startDateTime' => $start,
            'endDateTime' => $end,
        ];

        // Like filter_status[] above: the API 500s on Laravel's indexed
        // array encoding (assigning_seller_id[0]=…), so append the bare
        // []= form by hand.
        $sellerQuery = implode('', array_map(
            fn ($id) => '&assigning_seller_id[]='.urlencode($id),
            $sellerIds,
        ));
        $url = fn (int $page) => "{$this->basePos}/shops/{$shopId}/orders?"
            .http_build_query($baseQuery + ['page_number' => $page]).$sellerQuery;

        $customers = [];
        $sellerStats = [];
        $pageSellerCustomers = [];
        $productStats = [];
        $revenueTotal = 0;
        $orderCount = 0;

        $reduce = function (array $batch) use (&$customers, &$sellerStats, &$pageSellerCustomers, &$productStats, &$revenueTotal, &$orderCount) {
            foreach ($batch as $order) {
                $orderCount++;
                $orderTotal = $order['total_price'] ?? 0;
                $seller = $this->canonicalSellerName($order['assigning_seller']['name'] ?? null) ?? 'Unassigned';
                $customer = $order['customer'] ?? null;
                $customerId = $customer['customer_id'] ?? null;

                if ($customer && $customerId && ! isset($customers[$customerId])) {
                    $customers[$customerId] = [
                        'customer_id' => $customerId,
                        'name' => $customer['name'] ?? null,
                        'phone_numbers' => $customer['phone_numbers'] ?? [],
                        'gender' => $customer['gender'] ?? null,
                        'purchased_amount' => $customer['purchased_amount'] ?? 0,
                        'order_count' => $customer['order_count'] ?? 0,
                        'succeed_order_count' => $customer['succeed_order_count'] ?? 0,
                        'last_order_at' => $customer['last_order_at'] ?? null,
                        'sellers' => [],
                    ];
                }

                $sellerStats[$seller] ??= ['gross_sales' => 0, 'order_count' => 0, 'customer_ids' => []];
                $sellerStats[$seller]['gross_sales'] += $orderTotal;
                $sellerStats[$seller]['order_count']++;

                if ($customerId) {
                    $sellerStats[$seller]['customer_ids'][$customerId] = true;
                    $customers[$customerId]['sellers'][$seller]['orders'] = ($customers[$customerId]['sellers'][$seller]['orders'] ?? 0) + 1;
                    $customers[$customerId]['sellers'][$seller]['revenue'] = ($customers[$customerId]['sellers'][$seller]['revenue'] ?? 0) + $orderTotal;
                }

                $pageId = $order['page_id'] ?? null;

                if ($pageId && $customerId) {
                    $pageSellerCustomers[$pageId][$seller][$customerId] = true;
                }

                $revenueTotal += $orderTotal;

                foreach ($order['items'] ?? [] as $item) {
                    $name = $item['variation_info']['name'] ?? null;

                    if (! $name) {
                        continue;
                    }

                    $quantity = $item['quantity'] ?? 1;
                    $itemRevenue = ($item['variation_info']['retail_price'] ?? 0) * $quantity;
                    $stats = &$productStats[$name][$seller];
                    $stats['quantity'] = ($stats['quantity'] ?? 0) + $quantity;
                    $stats['revenue'] = ($stats['revenue'] ?? 0) + $itemRevenue;

                    if ($customerId) {
                        $stats['customer_ids'][$customerId] = ($stats['customer_ids'][$customerId] ?? 0) + $quantity;
                        $stats['customer_revenue'][$customerId] = ($stats['customer_revenue'][$customerId] ?? 0) + $itemRevenue;
                    }

                    unset($stats);
                }
            }
        };

        $first = Http::retry(3, 300)->get($url(1));
        $first->throw();
        $firstJson = $first->json();
        $totalEntries = $firstJson['total_entries'] ?? 0;
        $totalPages = min($firstJson['total_pages'] ?? 1, (int) ceil($maxOrders / $pageSize));
        $reduce($firstJson['data'] ?? []);
        unset($first, $firstJson);

        for ($chunkStart = 2; $chunkStart <= $totalPages; $chunkStart += $chunkSize) {
            $chunkEnd = min($chunkStart + $chunkSize - 1, $totalPages);
            $pagesInChunk = range($chunkStart, $chunkEnd);

            $responses = Http::pool(fn ($pool) => collect($pagesInChunk)->map(
                fn ($p) => $pool->as((string) $p)->retry(3, 300)->get($url($p))
            )->all());

            foreach ($pagesInChunk as $p) {
                if ($responses[(string) $p]->successful()) {
                    $reduce($responses[(string) $p]->json('data', []));
                }

                // Response objects cache their decoded body internally, so
                // leaving them in $responses until the whole chunk finishes
                // holds every page's full decoded array in memory at once —
                // this is what blew the memory limit in testing. Dropping
                // each one right after reducing lets it GC immediately.
                unset($responses[(string) $p]);
            }
        }

        return [
            'customers' => array_values($customers),
            'sellerStats' => $sellerStats,
            'pageSellerCustomers' => $pageSellerCustomers,
            'productStats' => $productStats,
            'pageNames' => $pageNames,
            'revenueTotal' => $revenueTotal,
            'orderCount' => $orderCount,
            'customerCount' => count($customers),
            'totalEntries' => $totalEntries,
            'truncated' => $totalEntries > $orderCount,
        ];
    }

    /**
     * Re-aggregate a summarizeCustomerActivityInRange payload down to a
     * subset of canonical seller names (or everything when $selectedSellers
     * is empty), producing the view model the Customers dashboard renders.
     * Pure PHP over the stored per-seller aggregates — no API calls — so
     * the seller picker works instantly against hourly snapshots.
     */
    public function sliceSummary(array $summary, array $selectedSellers = []): array
    {
        $sellerStats = $summary['sellerStats'] ?? [];
        $selected = $selectedSellers
            ? array_values(array_intersect(array_keys($sellerStats), $selectedSellers))
            : array_keys($sellerStats);
        $selectedSet = array_fill_keys($selected, true);

        $customers = collect($summary['customers'] ?? [])
            ->map(function ($c) use ($selectedSet) {
                $inPeriod = array_intersect_key($c['sellers'] ?? [], $selectedSet);

                if ($inPeriod === []) {
                    return null;
                }

                $c['crd_sellers'] = array_keys($inPeriod);
                $c['period_revenue'] = array_sum(array_column($inPeriod, 'revenue'));
                $c['period_orders'] = array_sum(array_column($inPeriod, 'orders'));

                return $c;
            })
            ->filter()
            ->values();

        $lifetimeOrders = $customers->pluck('order_count', 'customer_id');

        $sellerBreakdown = collect($sellerStats)
            ->only($selected)
            ->map(function ($stats, $name) use ($lifetimeOrders) {
                $customerIds = array_keys($stats['customer_ids'] ?? []);

                return [
                    'name' => $name,
                    'gross_sales' => $stats['gross_sales'],
                    'order_count' => $stats['order_count'],
                    'customer_count' => count($customerIds),
                    'avg_order' => $stats['order_count'] > 0 ? $stats['gross_sales'] / $stats['order_count'] : 0,
                    // Buyers this period whose lifetime order count is > 1 —
                    // i.e. not first-ever purchases. The retention signal
                    // a CRD team is judged on.
                    'repeat_customers' => count(array_filter($customerIds, fn ($id) => ($lifetimeOrders[$id] ?? 0) > 1)),
                ];
            })
            ->sortByDesc('gross_sales')
            ->values();

        $revenueTotal = $sellerBreakdown->sum('gross_sales');
        $orderCount = $sellerBreakdown->sum('order_count');

        // Ranked by what they spent with the selected CRDs *this period*,
        // not lifetime LTV — the table below already sorts by LTV.
        $topCustomers = $customers
            ->sortByDesc(fn ($c) => $c['period_revenue'] ?? 0)
            ->take(5)
            ->values();

        // Orders can come from Facebook pages we haven't registered locally
        // (no FacebookPage row, so there's no real name for them) — those
        // are dropped rather than shown as a bare numeric page_id.
        $pageNames = $summary['pageNames'] ?? [];
        $customersPerPage = collect($summary['pageSellerCustomers'] ?? [])
            ->filter(fn ($bySeller, $pageId) => isset($pageNames[$pageId]))
            ->map(function ($bySeller, $pageId) use ($selectedSet, $pageNames) {
                $ids = [];

                foreach (array_intersect_key($bySeller, $selectedSet) as $customerIds) {
                    $ids += $customerIds;
                }

                return [
                    'page_id' => $pageId,
                    'page_name' => $pageNames[$pageId],
                    'count' => count($ids),
                ];
            })
            ->filter(fn ($p) => $p['count'] > 0)
            ->sortByDesc('count')
            ->values();

        $customerNames = $customers->pluck('name', 'customer_id');

        $topProducts = collect($summary['productStats'] ?? [])
            ->map(function ($bySeller, $name) use ($selectedSet, $customerNames) {
                $quantity = 0;
                $revenue = 0;
                $customerQty = [];
                $customerRevenue = [];

                foreach (array_intersect_key($bySeller, $selectedSet) as $stats) {
                    $quantity += $stats['quantity'];
                    $revenue += $stats['revenue'];

                    foreach ($stats['customer_ids'] ?? [] as $id => $qty) {
                        $customerQty[$id] = ($customerQty[$id] ?? 0) + $qty;
                    }

                    foreach ($stats['customer_revenue'] ?? [] as $id => $amount) {
                        $customerRevenue[$id] = ($customerRevenue[$id] ?? 0) + $amount;
                    }
                }

                $topCustomerId = collect($customerQty)->sortDesc()->keys()->first();

                return [
                    'name' => $name,
                    'quantity' => $quantity,
                    'revenue' => $revenue,
                    'top_customer' => $topCustomerId ? ($customerNames[$topCustomerId] ?? null) : null,
                    'top_customer_value' => $topCustomerId ? ($customerRevenue[$topCustomerId] ?? 0) : null,
                ];
            })
            ->filter(fn ($p) => $p['quantity'] > 0)
            ->sortByDesc('revenue')
            ->take(5)
            ->values();

        return [
            'customers' => $customers,
            'sellerBreakdown' => $sellerBreakdown,
            'topCustomers' => $topCustomers,
            'customersPerPage' => $customersPerPage,
            'topProducts' => $topProducts,
            'revenueTotal' => $revenueTotal,
            'orderCount' => $orderCount,
            'customerCount' => $customers->count(),
            'totalEntries' => $summary['totalEntries'] ?? 0,
            'truncated' => $summary['truncated'] ?? false,
        ];
    }

    public function getCustomer(string $shopId, string $apiKey, string $customerId): ?array
    {
        $response = Http::retry(3, 300)->get("{$this->basePos}/shops/{$shopId}/customers/{$customerId}", [
            'api_key' => $apiKey,
        ]);

        if ($response->failed()) {
            return null;
        }

        return $response->json('data');
    }

    /**
     * A single customer's full order history, newest first. Bounded to a
     * few hundred orders (well beyond what any real customer would have)
     * rather than looping indefinitely.
     */
    public function customerOrders(string $shopId, string $apiKey, string $customerId, int $maxOrders = 200): array
    {
        $orders = [];
        $pageNumber = 1;

        do {
            $response = Http::retry(3, 300)->get("{$this->basePos}/shops/{$shopId}/orders", [
                'api_key' => $apiKey,
                'page_size' => 100,
                'page_number' => $pageNumber,
                'customer_id' => $customerId,
                'option_sort' => 'inserted_at_desc',
            ]);

            $response->throw();
            $batch = $response->json('data', []);
            $orders = array_merge($orders, $batch);
            $pageNumber++;
        } while (count($batch) === 100 && count($orders) < $maxOrders);

        return $orders;
    }

    /**
     * Tally item quantities across a customer's orders (by product variation
     * name) and return the single highest-quantity product, or null if the
     * customer has no orders with line items.
     */
    public function topPurchasedProduct(array $orders): ?array
    {
        $totals = [];

        foreach ($orders as $order) {
            foreach ($order['items'] ?? [] as $item) {
                $name = $item['variation_info']['name'] ?? null;

                if (! $name) {
                    continue;
                }

                $totals[$name] = ($totals[$name] ?? 0) + ($item['quantity'] ?? 1);
            }
        }

        if ($totals === []) {
            return null;
        }

        arsort($totals);
        $name = array_key_first($totals);

        return ['name' => $name, 'quantity' => $totals[$name]];
    }

    /**
     * Stream inbox conversations with activity (updated_at) in the given
     * window, invoking the callback once per conversation and discarding
     * each batch afterwards — busy pages have tens of thousands of
     * conversations per day, far too many to accumulate in memory.
     *
     * Ordering by updated_at makes since/until filter on activity date, so
     * old threads that received new messages today are included. Ordering
     * by inserted_at instead would only return threads *created* in the
     * window, missing nearly all retention traffic.
     *
     * @param  callable(array): void  $callback
     * @return int Number of inbox conversations processed.
     */
    public function eachInboxConversation(FacebookPage $page, string $startDate, string $endDate, callable $callback): int
    {
        $since = Carbon::parse($startDate)->startOfDay()->timestamp;
        $until = Carbon::parse($endDate)->endOfDay()->timestamp;

        $processed = 0;
        $lastConversationId = null;

        do {
            $query = array_filter([
                'page_access_token' => $page->access_token,
                'order_by' => 'updated_at',
                'since' => $since,
                'until' => $until,
                'last_conversation_id' => $lastConversationId,
            ]);

            // The `type[]=INBOX` server-side filter causes this endpoint to return
            // a 500, so fetch unfiltered (INBOX + COMMENT) and filter client-side.
            $url = "{$this->baseV2}/pages/{$page->page_id}/conversations?"
                . http_build_query($query);

            $response = Http::retry(3, 300)->get($url);
            $response->throw();

            $batch = $response->json('conversations', []);

            foreach ($batch as $conversation) {
                if (($conversation['type'] ?? null) === 'INBOX') {
                    $callback($conversation);
                    $processed++;
                }
            }

            $lastConversationId = count($batch) === 60 ? end($batch)['id'] : null;

            unset($response, $batch);
        } while ($lastConversationId);

        return $processed;
    }

   public function getEngagement(FacebookPage $page, string $startDate, string $endDate): ?array
    {
        $dateRange = Carbon::parse($startDate)->format('d/m/Y') . ' 00:00:00 - '
            . Carbon::parse($endDate)->format('d/m/Y') . ' 23:59:59';

        $response = Http::retry(3, 300)->get("{$this->baseV1}/pages/{$page->page_id}/statistics/customer_engagements", [
            'page_access_token' => $page->access_token,
            'date_range' => $dateRange,
        ]);

        $response->throw();

        $json = $response->json();

        if (! ($json['success'] ?? false)) {
            return null;
        }

        $series = collect($json['data']['series'] ?? [])->keyBy('name');
        $sum = fn (string $key) => array_sum($series[$key]['data'] ?? []);

        return [
            'inbox' => $sum('inbox'),
            'comment' => $sum('comment'),
            'total' => $sum('total'),
            'new_customer_replied' => $sum('new_customer_replied'),
            'new_inbox_customers' => $sum('customer_engagement_new_inbox'),
            'order_count' => $sum('order_count'),
            'old_order_count' => $sum('old_order_count'),
            'categories' => $json['data']['categories'] ?? [],
            'series' => $json['data']['series'] ?? [],
            'users_engagements' => $json['users_engagements'] ?? [],
        ];
    }

   public function getOverallSummary($pages, string $startDate, string $endDate): array
    {
        $perPage = [];
        $totals = ['inbox' => 0, 'comment' => 0, 'total' => 0, 'order_count' => 0, 'old_order_count' => 0, 'new_inbox_customers' => 0];

        foreach ($pages as $page) {
            try {
                $engagement = $this->getEngagement($page, $startDate, $endDate);
            } catch (\Throwable $e) {
                $engagement = null;
            }

            $perPage[] = ['page' => $page, 'engagement' => $engagement];

            if ($engagement) {
                foreach ($totals as $key => $value) {
                    $totals[$key] += $engagement[$key] ?? 0;
                }
            }
        }

        return ['totals' => $totals, 'per_page' => $perPage];
    }
}