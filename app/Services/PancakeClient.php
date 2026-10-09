<?php

namespace App\Services;

use App\Models\PancakePage;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class PancakeClient
{
    /** Order fields Segmentation Productivity and Conversion Breakdown use; asked for with fields[] since full orders are large. */
    private const ORDER_FIELDS = [
        'id', 'display_id', 'inserted_at', 'status', 'status_name', 'bill_phone_number', 'bill_full_name',
        'customer', 'total_price', 'account_name', 'assigning_seller', 'creator', 'tags', 'items',
    ];

    /** What a tag or status refresh keeps of an order. */
    private const CHANGE_FIELDS = ['id', 'display_id', 'tags', 'status', 'status_name'];

    /**
     * Chat → Analytics → Engagements → Customer engagement for one day, per
     * staff account, summed over every active page in Settings → Pancake Pages.
     *
     * Filtering the API by user_ids returns zeros, so each page is read
     * unfiltered and its users_engagements list is used instead.
     *
     * @return array<string, array{name: string, engagements: int}> keyed by Pancake user id
     */
    public function engagements(CarbonImmutable $day): array
    {
        $pages = PancakePage::active()->get();

        if ($pages->isEmpty()) {
            throw new RuntimeException('No active Pancake pages. Add them in Settings → Pancake Pages.');
        }

        $staff = [];

        foreach ($pages as $page) {
            $response = $this->engagementRequest($page, $day);

            if ($response->failed() || ! $response->json('success')) {
                throw new RuntimeException("Pancake engagements for page {$page->name} ({$page->page_id}) returned HTTP {$response->status()}.");
            }

            foreach ($response->json('users_engagements') ?? [] as $row) {
                $id = (string) ($row['user_id'] ?? '');

                if ($id === '') {
                    continue;
                }

                $staff[$id] ??= ['name' => (string) ($row['name'] ?? ''), 'engagements' => 0];
                $staff[$id]['engagements'] += (int) ($row['total_engagement'] ?? 0);
            }
        }

        return $staff;
    }

    /**
     * Whether Pancake accepts the page's token: one engagements request for today.
     *
     * @return array{0: bool, 1: string}
     */
    public function checkPage(PancakePage $page): array
    {
        try {
            $response = $this->engagementRequest($page, CarbonImmutable::now(config('segmentation.timezone')));
        } catch (\Throwable $e) {
            return [false, "Couldn't reach Pancake: {$e->getMessage()}"];
        }

        if ($response->json('success')) {
            return [true, 'Connected: Pancake accepted the token.'];
        }

        return [false, 'Pancake refused the token (HTTP '.$response->status().'). Check the page ID and copy a fresh page access token from Pancake → Settings → Tools.'];
    }

    private function engagementRequest(PancakePage $page, CarbonImmutable $day): Response
    {
        return Http::acceptJson()
            ->timeout(60)
            ->retry(2, 1000, throw: false)
            ->get(rtrim(config('services.pancake.chat_url'), '/')."/pages/{$page->page_id}/statistics/customer_engagements", [
                'page_access_token' => $page->access_token,
                'date_range' => $day->format('d/m/Y').' 00:00:00 - '.$day->format('d/m/Y').' 23:59:59',
            ]);
    }

    /**
     * Orders that became Delivered on $day and still are (a later return drops
     * them), with their items, for the lead fallback.
     *
     * @return list<array{order_id: string, tracking_number: ?string, customer_name: string, phone_number: string, items: list<array{name: string, qty: int}>}>
     */
    public function deliveredOrders(CarbonImmutable $day): array
    {
        $start = CarbonImmutable::parse($day->toDateString(), config('segmentation.timezone'));
        $fields = ['display_id', 'id', 'status', 'bill_phone_number', 'bill_full_name', 'customer', 'items', 'partner'];
        $orders = [];
        $page = 1;

        do {
            $body = $this->posPage([
                'page_size' => 100,
                'page_number' => $page,
                'startDateTime' => $start->getTimestamp(),
                'endDateTime' => $start->endOfDay()->getTimestamp(),
                // Filter by the time the order's status became 3 (Delivered).
                'updateStatus' => '3',
            ], $fields);

            foreach ($body['data'] ?? [] as $order) {
                if ((int) ($order['status'] ?? 0) !== 3) {
                    continue;
                }

                $orders[] = [
                    'order_id' => (string) ($order['display_id'] ?? $order['id']),
                    'tracking_number' => $order['partner']['extend_code'] ?? null,
                    'customer_name' => trim($order['bill_full_name'] ?? ($order['customer']['name'] ?? '')),
                    'phone_number' => (string) ($order['bill_phone_number'] ?? ($order['customer']['phone_numbers'][0] ?? '')),
                    'items' => self::slimItems($order['items'] ?? []),
                ];
            }

            $totalPages = (int) ($body['total_pages'] ?? 1);
            $page++;
        } while ($page <= $totalPages);

        return $orders;
    }

    /**
     * Whole orders (orders()' shape) that became Delivered on $day and still are, for the
     * Customer Database's days before the logistics report starts.
     *
     * @return list<array<string, mixed>>
     */
    public function deliveredOn(CarbonImmutable $day): array
    {
        $start = CarbonImmutable::parse($day->toDateString(), config('segmentation.timezone'));
        $orders = [];
        $page = 1;

        do {
            $body = $this->posPage([
                'page_size' => 100,
                'page_number' => $page,
                'startDateTime' => $start->getTimestamp(),
                'endDateTime' => $start->endOfDay()->getTimestamp(),
                'updateStatus' => '3',
            ], self::ORDER_FIELDS);

            foreach ($body['data'] ?? [] as $order) {
                if ((int) ($order['status'] ?? 0) === 3) {
                    $order['items'] = self::slimItems($order['items'] ?? []);
                    $orders[] = array_intersect_key($order, array_flip(self::ORDER_FIELDS));
                }
            }

            $totalPages = (int) ($body['total_pages'] ?? 1);
            unset($body);
            $page++;
        } while ($page <= $totalPages);

        return $orders;
    }

    /**
     * An order's items as product name and quantity.
     *
     * @param  list<array<string, mixed>>  $items
     * @return list<array{name: string, qty: int}>
     */
    private static function slimItems(array $items): array
    {
        return collect($items)->map(fn (array $item) => [
            'name' => trim((string) ($item['variation_info']['name'] ?? '')),
            'qty' => max(1, (int) ($item['quantity'] ?? 1)),
        ])->filter(fn (array $item) => $item['name'] !== '')->values()->all();
    }

    /**
     * One page of POS orders with only $fields, authenticated with the access token or API key.
     *
     * @param  array<string, mixed>  $query
     * @param  list<string>  $fields
     * @return array<string, mixed>
     */
    private function posPage(array $query, array $fields): array
    {
        // No Accept: application/json here; with it the POS API stalls mid-response.
        $response = Http::timeout(90)->retry(3, 2000, throw: false)->get($this->posOrdersUrl($query, $fields));
        $body = $response->json();

        if ($response->failed() || ! ($body['success'] ?? false)) {
            throw new RuntimeException("Pancake POS orders returned HTTP {$response->status()}: ".($body['message'] ?? 'unknown error'));
        }

        return $body;
    }

    /**
     * The POS orders URL with the shop's credentials, $query and the $fields to return.
     *
     * @param  array<string, mixed>  $query
     * @param  list<string>  $fields
     */
    private function posOrdersUrl(array $query, array $fields): string
    {
        $shop = config('services.pancake.shop_id');
        $auth = config('services.pancake.access_token')
            ? ['access_token' => config('services.pancake.access_token')]
            : ['api_key' => config('services.pancake.key')];

        if (! reset($auth) || ! $shop) {
            throw new RuntimeException('PANCAKE_SHOP_ID and either PANCAKE_ACCESS_TOKEN or PANCAKE_API_KEY must be set.');
        }

        return rtrim(config('services.pancake.pos_url'), '/')."/shops/{$shop}/orders?".http_build_query([...$auth, ...$query])
            .'&'.collect($fields)->map(fn (string $field) => 'fields[]='.$field)->join('&');
    }

    /**
     * Every POS order changed on $day (segmentation timezone), whenever it was created: only its id,
     * tags and status. Catches CRD tags added or changed days after the order was placed. With $since,
     * only the changes from then to the end of $day.
     *
     * @return list<array{display_id?: int|string, id?: string, tags?: list<mixed>, status?: int, status_name?: string}>
     */
    public function updatedOrders(CarbonImmutable $day, ?CarbonImmutable $since = null): array
    {
        $start = CarbonImmutable::parse($day->toDateString(), config('segmentation.timezone'));
        $from = $since && $since->greaterThan($start) ? $since : $start;
        $orders = [];
        $page = 1;

        do {
            $body = $this->posPage([
                'page_size' => 50,
                'page_number' => $page,
                'startDateTime' => $from->getTimestamp(),
                'endDateTime' => $start->endOfDay()->getTimestamp(),
                'updateStatus' => 'updated_at',
            ], self::CHANGE_FIELDS);

            // Pancake ignores fields[] here and sends whole orders; keep only these so a busy day fits in memory.
            foreach ($body['data'] ?? [] as $order) {
                $orders[] = array_intersect_key($order, array_flip(self::CHANGE_FIELDS));
            }

            $totalPages = (int) ($body['total_pages'] ?? 1);
            unset($body);
            $page++;
        } while ($page <= $totalPages);

        return $orders;
    }

    /**
     * The current id, tags and status of the orders with these order numbers (display_id), found with
     * Pancake's order search, ten at a time. Numbers Pancake doesn't find, or that fail, are left out.
     * With $full, whole orders in orders()' shape (amount, seller, items…).
     *
     * @param  list<string>  $numbers
     * @return list<array{display_id?: int|string, id?: string, tags?: list<mixed>, status?: int, status_name?: string}>
     */
    public function ordersByNumber(array $numbers, bool $full = false): array
    {
        $orders = [];
        $fields = $full ? self::ORDER_FIELDS : self::CHANGE_FIELDS;

        foreach (array_chunk(array_values(array_unique($numbers)), 10) as $chunk) {
            $responses = Http::pool(fn (Pool $pool) => array_map(
                fn (string $number) => $pool->as($number)->timeout(60)->get($this->posOrdersUrl(['search' => $number], $fields)),
                $chunk,
            ));

            foreach ($chunk as $number) {
                $response = $responses[$number] ?? null;

                if (! $response instanceof Response || $response->failed()) {
                    continue;
                }

                // The search also matches phone numbers and notes; keep the order with this number.
                $order = collect($response->json('data') ?? [])->first(fn ($order) => (string) ($order['display_id'] ?? '') === $number);

                if ($order) {
                    if ($full) {
                        $order['items'] = self::slimItems($order['items'] ?? []);
                    }
                    $orders[] = array_intersect_key($order, array_flip($fields));
                }
            }
        }

        return $orders;
    }

    /**
     * Every POS order created on $day (segmentation timezone), trimmed to the
     * fields Segmentation Productivity uses; full orders are large.
     *
     * @return list<array<string, mixed>>
     */
    public function orders(CarbonImmutable $day): array
    {
        $shop = config('services.pancake.shop_id');
        // A user access token works when the shop's API keys are refused; otherwise the API key.
        $auth = config('services.pancake.access_token')
            ? ['access_token' => config('services.pancake.access_token')]
            : ['api_key' => config('services.pancake.key')];

        if (! reset($auth) || ! $shop) {
            throw new RuntimeException('PANCAKE_SHOP_ID and either PANCAKE_ACCESS_TOKEN or PANCAKE_API_KEY must be set in .env.');
        }

        $start = CarbonImmutable::parse($day->toDateString(), config('segmentation.timezone'));
        $orders = [];
        $page = 1;

        do {
            // No Accept: application/json here; with it the POS API stalls mid-response.
            $response = Http::timeout(90)
                ->retry(3, 2000, throw: false)
                ->get(rtrim(config('services.pancake.pos_url'), '/')."/shops/{$shop}/orders?".http_build_query([
                    ...$auth,
                    'page_size' => 50,
                    'page_number' => $page,
                    'startDateTime' => $start->getTimestamp(),
                    'endDateTime' => $start->endOfDay()->getTimestamp(),
                    'updateStatus' => 'inserted_at',
                ]).'&'.collect(self::ORDER_FIELDS)->map(fn (string $field) => 'fields[]='.$field)->join('&'));

            $body = $response->json();

            if ($response->failed() || ! ($body['success'] ?? false)) {
                throw new RuntimeException("Pancake POS orders returned HTTP {$response->status()}: ".($body['message'] ?? 'unknown error'));
            }

            foreach ($body['data'] ?? [] as $order) {
                // Items carry the whole product record; keep only name and qty.
                $order['items'] = self::slimItems($order['items'] ?? []);
                $orders[] = array_intersect_key($order, array_flip(self::ORDER_FIELDS));
            }

            $totalPages = (int) ($body['total_pages'] ?? 1);
            unset($body, $response);
            $page++;
        } while ($page <= $totalPages);

        return $orders;
    }
}
