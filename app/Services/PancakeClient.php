<?php

namespace App\Services;

use App\Models\PancakePage;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class PancakeClient
{
    /** Order fields Segmentation Productivity uses; asked for with fields[] since full orders are large. */
    private const ORDER_FIELDS = [
        'id', 'display_id', 'inserted_at', 'status', 'status_name', 'bill_phone_number', 'bill_full_name',
        'customer', 'total_price', 'account_name', 'assigning_seller', 'creator',
    ];

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
                    'items' => collect($order['items'] ?? [])->map(fn (array $item) => [
                        'name' => trim((string) ($item['variation_info']['name'] ?? '')),
                        'qty' => max(1, (int) ($item['quantity'] ?? 1)),
                    ])->filter(fn (array $item) => $item['name'] !== '')->values()->all(),
                ];
            }

            $totalPages = (int) ($body['total_pages'] ?? 1);
            $page++;
        } while ($page <= $totalPages);

        return $orders;
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
        $shop = config('services.pancake.shop_id');
        $auth = config('services.pancake.access_token')
            ? ['access_token' => config('services.pancake.access_token')]
            : ['api_key' => config('services.pancake.key')];

        if (! reset($auth) || ! $shop) {
            throw new RuntimeException('PANCAKE_SHOP_ID and either PANCAKE_ACCESS_TOKEN or PANCAKE_API_KEY must be set.');
        }

        // No Accept: application/json here; with it the POS API stalls mid-response.
        $response = Http::timeout(90)->retry(3, 2000, throw: false)->get(
            rtrim(config('services.pancake.pos_url'), '/')."/shops/{$shop}/orders?".http_build_query([...$auth, ...$query])
            .'&'.collect($fields)->map(fn (string $field) => 'fields[]='.$field)->join('&')
        );
        $body = $response->json();

        if ($response->failed() || ! ($body['success'] ?? false)) {
            throw new RuntimeException("Pancake POS orders returned HTTP {$response->status()}: ".($body['message'] ?? 'unknown error'));
        }

        return $body;
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
                $orders[] = array_intersect_key($order, array_flip(self::ORDER_FIELDS));
            }

            $totalPages = (int) ($body['total_pages'] ?? 1);
            unset($body, $response);
            $page++;
        } while ($page <= $totalPages);

        return $orders;
    }
}
