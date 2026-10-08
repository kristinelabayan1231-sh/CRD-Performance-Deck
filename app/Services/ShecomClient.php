<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class ShecomClient
{
    /**
     * All delivered orders with their estimated out-of-stock date. The same
     * response's retention numbers are kept for the dashboard on the way.
     *
     * @return list<array{order_id: string, tracking_number: ?string, customer_name: string, phone_number: string, product_name: string, qty: int, delivered_date: string, consumption_days_per_unit: int, estimated_out_of_stock_date: string}>
     */
    public function retentionStockouts(): array
    {
        return $this->leadSources()['crd'];
    }

    /**
     * Where the day's leads come from: CRD-delivered orders with their
     * out-of-stock date (stock_outs) and FSD-delivered orders (no qty or
     * out-of-stock date; the lead generator works those out). The retention
     * numbers are kept for the dashboard on the way.
     *
     * @return array{crd: list<array<string, mixed>>, fsd: list<array{order_id: string, tracking_number: ?string, customer_name: string, phone_number: string, product_name: string, delivered_date: string}>}
     */
    public function leadSources(): array
    {
        $report = $this->report();
        LogisticsRetention::store($report);

        return ['crd' => $report['stock_outs'], 'fsd' => $report['fsd_orders']];
    }

    /**
     * The retention report, with its large per-order retention lists reduced to
     * daily counts (by delivered date) as soon as it is read. The FSD orders are
     * kept in a slim form for the lead generator.
     *
     * @return array{stock_outs: list<array<string, mixed>>, fsd_orders: list<array<string, mixed>>, summary: array<string, int|float>, days: array<string, array{fb_delivered: int, fb_retained: int, crd_delivered: int, crd_again: int}>}
     */
    public function report(): array
    {
        $body = $this->fetch();

        $fsdOrders = array_map(fn (array $row) => [
            'order_id' => (string) ($row['order_id'] ?? ''),
            'tracking_number' => $row['tracking_number'] ?? null,
            'customer_name' => (string) ($row['customer_name'] ?? ''),
            'phone_number' => (string) ($row['phone_number'] ?? ''),
            'product_name' => (string) ($row['product'] ?? ''),
            'delivered_date' => substr((string) ($row['delivered_date'] ?? ''), 0, 10),
        ], $body['retention_detail'] ?? []);

        $days = [];
        $count = function (string $list, string $total, string $flagKey, string $flagged) use (&$body, &$days) {
            foreach ($body[$list] ?? [] as $row) {
                $day = substr((string) ($row['delivered_date'] ?? ''), 0, 10);

                if ($day === '') {
                    continue;
                }

                $days[$day] ??= ['fb_delivered' => 0, 'fb_retained' => 0, 'crd_delivered' => 0, 'crd_again' => 0];
                $days[$day][$total]++;
                $days[$day][$flagged] += ! empty($row[$flagKey]) ? 1 : 0;
            }
            unset($body[$list]);
        };
        $count('retention_detail', 'fb_delivered', 'retained_by_crd', 'fb_retained');
        $count('repeat_detail', 'crd_delivered', 'ordered_again_via_crd', 'crd_again');
        ksort($days);

        return [
            'stock_outs' => $body['stock_outs'] ?? [],
            'fsd_orders' => $fsdOrders,
            'summary' => $body['retention_summary'] ?? [],
            'days' => $days,
        ];
    }

    /**
     * Every delivered order, split by the team that sold it: CRD (repeat
     * orders, plus the out-of-stock list) and FSD (Facebook Sales).
     *
     * @return array{crd: list<array<string, mixed>>, fsd: list<array<string, mixed>>}
     */
    public function deliveredOrders(): array
    {
        return self::splitDelivered($this->fetch());
    }

    /**
     * @param  array<string, mixed>  $body  the decoded retention report
     * @return array{crd: list<array<string, mixed>>, fsd: list<array<string, mixed>>}
     */
    public static function splitDelivered(array $body): array
    {
        return [
            'crd' => [...($body['stock_outs'] ?? []), ...($body['repeat_detail'] ?? [])],
            'fsd' => $body['retention_detail'] ?? [],
        ];
    }

    /**
     * Each order's sales for orders dated $from–$to, by order id. Shecom leaves
     * out the child row (TSD only), so this is the CRA's own sale.
     *
     * @return array<string, float> order id => sales
     */
    public function sales(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $key = config('services.shecom.sales_key');

        if (! $key) {
            throw new RuntimeException('SHECOM_SALES_API_KEY is not set in .env.');
        }

        $response = Http::withToken($key)
            ->acceptJson()
            ->timeout(120)
            ->retry(2, 2000, throw: false)
            ->get(rtrim(config('services.shecom.url'), '/').'/management/sales', [
                'date_from' => $from->toDateString(),
                'date_to' => $to->toDateString(),
            ]);

        if ($response->failed()) {
            throw new RuntimeException("Sales API returned HTTP {$response->status()}: ".$response->json('error', 'unknown error'));
        }

        return collect($response->json('orders') ?? [])
            ->filter(fn (array $order) => ($order['order_id'] ?? '') !== '' && is_numeric($order['sales'] ?? null))
            ->mapWithKeys(fn (array $order) => [(string) $order['order_id'] => (float) $order['sales']])
            ->all();
    }

    /**
     * The decoded retention report.
     *
     * @return array<string, mixed>
     */
    private function fetch(): array
    {
        $key = config('services.shecom.key');

        if (! $key) {
            throw new RuntimeException('SHECOM_API_KEY is not set in .env.');
        }

        // The full report is about 18 MB of JSON (~150 MB once decoded), above PHP's default 128 MB.
        $this->raiseMemoryLimit(config('services.shecom.memory_limit'));

        $response = Http::withToken($key)
            ->acceptJson()
            ->timeout(180)
            ->retry(2, 2000, throw: false)
            ->get(rtrim(config('services.shecom.url'), '/').'/management/retention-stockout');

        if ($response->failed()) {
            throw new RuntimeException("Retention API returned HTTP {$response->status()}: ".$response->json('error', 'unknown error'));
        }

        return json_decode($response->body(), true) ?: [];
    }

    private function raiseMemoryLimit(string $limit): void
    {
        $bytes = fn (string $value) => $value === '-1' ? PHP_INT_MAX : (int) $value * match (strtoupper(substr($value, -1))) {
            'G' => 1024 ** 3, 'M' => 1024 ** 2, 'K' => 1024, default => 1,
        };

        if ($bytes((string) ini_get('memory_limit')) < $bytes($limit)) {
            ini_set('memory_limit', $limit);
        }
    }
}
