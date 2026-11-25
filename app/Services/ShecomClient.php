<?php

namespace App\Services;

use App\Models\LogisticsOrder;
use Carbon\CarbonImmutable;
use Closure;
use Generator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use JsonMachine\Exception\JsonMachineException;
use JsonMachine\Exception\PathNotFoundException;
use JsonMachine\Items;
use JsonMachine\JsonDecoder\ExtJsonDecoder;
use RuntimeException;

class ShecomClient
{
    /** Held while the full retention report is read and worked on. */
    public const REPORT_LOCK = 'shecom-report';

    /** The department of a CRA's row in Shecom's orders report. */
    private const RETENTION_DEPT = 'retention';

    /**
     * Run $work (which reads the full retention report) once no other process is: the web service, the scheduler's
     * lead sync and the logistics refresh share one small instance, and two reports at once run it out of memory.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $work
     * @return TResult
     */
    public static function oneAtATime(Closure $work): mixed
    {
        return Cache::lock(self::REPORT_LOCK, 900)->block(600, $work);
    }

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
        LogisticsOrder::remember($report['delivered']);

        return ['crd' => $report['stock_outs'], 'fsd' => $report['fsd_orders']];
    }

    /**
     * The retention report, with its large per-order retention lists reduced to
     * daily counts (by delivered date) as it is read. The FSD orders are kept in a
     * slim form for the lead generator, and every delivered order for the Customer Database.
     *
     * The report (~18 MB of JSON, ~110 MB if decoded at once) is read one row at a time as it
     * downloads, so the small instance's web server and syncs aren't run out of memory.
     *
     * @return array{stock_outs: list<array<string, mixed>>, fsd_orders: list<array<string, mixed>>, delivered: list<array<string, mixed>>, summary: array<string, int|float>, days: array<string, array{fb_delivered: int, fb_retained: int, crd_delivered: int, crd_again: int}>}
     */
    public function report(): array
    {
        $stockOuts = [];
        $summary = [];
        $fsdOrders = [];
        $days = [];
        // Every delivered order by list: a CRD order on the out-of-stock list wins over the repeat list, which wins over FSD.
        $delivered = ['stock_outs' => [], 'repeat_detail' => [], 'retention_detail' => []];

        $countDay = function (array $row, string $total, string $flagKey, string $flagged) use (&$days): void {
            $day = substr((string) ($row['delivered_date'] ?? ''), 0, 10);

            if ($day === '') {
                return;
            }

            $days[$day] ??= ['fb_delivered' => 0, 'fb_retained' => 0, 'crd_delivered' => 0, 'crd_again' => 0];
            $days[$day][$total]++;
            $days[$day][$flagged] += ! empty($row[$flagKey]) ? 1 : 0;
        };

        $items = Items::fromIterable($this->download(), [
            'pointer' => ['/stock_outs', '/retention_summary', '/retention_detail', '/repeat_detail'],
            'decoder' => new ExtJsonDecoder(true),
        ]);

        try {
            foreach ($items as $key => $row) {
                $list = substr($items->getMatchedJsonPointer(), 1);

                if ($list === 'retention_summary') {
                    $summary[$key] = $row;

                    continue;
                }

                if ($list === 'stock_outs') {
                    $stockOuts[] = $row;
                    $delivered[$list][] = self::slimDelivered($row, LogisticsOrder::TEAM_CRD);

                    continue;
                }

                if ($list === 'retention_detail') {
                    $fsdOrders[] = [
                        'order_id' => (string) ($row['order_id'] ?? ''),
                        'tracking_number' => $row['tracking_number'] ?? null,
                        'customer_name' => (string) ($row['customer_name'] ?? ''),
                        'phone_number' => (string) ($row['phone_number'] ?? ''),
                        'product_name' => (string) ($row['product'] ?? ''),
                        'delivered_date' => substr((string) ($row['delivered_date'] ?? ''), 0, 10),
                    ];
                    $delivered[$list][] = self::slimDelivered($row, LogisticsOrder::TEAM_FSD);
                    $countDay($row, 'fb_delivered', 'retained_by_crd', 'fb_retained');

                    continue;
                }

                $delivered[$list][] = self::slimDelivered($row, LogisticsOrder::TEAM_CRD);
                $countDay($row, 'crd_delivered', 'ordered_again_via_crd', 'crd_again');
            }
        } catch (PathNotFoundException) {
            // Raised only once the whole report is read: a list the report doesn't have stays empty.
        } catch (JsonMachineException) {
            // A body that isn't valid JSON counts as an empty report, as it always has.
            return ['stock_outs' => [], 'fsd_orders' => [], 'delivered' => [], 'summary' => [], 'days' => []];
        }

        ksort($days);

        $orders = [];

        foreach ($delivered as $rows) {
            foreach ($rows as $order) {
                $orders[$order['order_id']] ??= $order;
            }
        }

        return [
            'stock_outs' => $stockOuts,
            'fsd_orders' => $fsdOrders,
            'delivered' => array_values($orders),
            'summary' => $summary,
            'days' => $days,
        ];
    }

    /**
     * A delivered order slimmed for the Customer Database.
     *
     * @param  array<string, mixed>  $row
     * @return array{order_id: string, team: string, customer_name: string, phone_number: string, product: string, qty: ?int, delivered_date: string}
     */
    private static function slimDelivered(array $row, string $team): array
    {
        return [
            'order_id' => (string) ($row['order_id'] ?? ''),
            'team' => $team,
            'customer_name' => trim((string) ($row['customer_name'] ?? '')),
            'phone_number' => (string) ($row['phone_number'] ?? ''),
            'product' => trim((string) ($row['product_name'] ?? $row['product'] ?? '')),
            'qty' => isset($row['qty']) ? max(1, (int) $row['qty']) : null,
            'delivered_date' => substr((string) ($row['delivered_date'] ?? ''), 0, 10),
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
     * Each Pancake item's current cost (null where Shecom has none yet) and the day it took effect.
     *
     * @return list<array{product: string, currentCost: int|float|null, currentEffectiveFrom: ?string}>
     */
    public function productCosts(): array
    {
        $key = config('services.shecom.product_cost_key');

        if (! $key) {
            throw new RuntimeException('SHECOM_PRODUCT_COST_API_KEY is not set in .env.');
        }

        $response = Http::withToken($key)
            ->acceptJson()
            ->timeout(60)
            ->retry(2, 2000, throw: false)
            ->get(rtrim(config('services.shecom.external_url'), '/').'/product-costs');

        if ($response->failed()) {
            throw new RuntimeException("Product costs API returned HTTP {$response->status()}: ".$response->json('error', 'unknown error'));
        }

        return $response->json('products') ?? [];
    }

    /**
     * Each order's product cost for orders dated $from–$to, by order id (Pancake's display id), as Shecom's sales
     * report counts it per CRA and product, and the CRA's own sale of each order with a child row. Such an order has a
     * Retention row (the mother item) and another department's (the child, e.g. a Telesales upsell); only the Retention
     * row is the CRA's. Its cost is null while Shecom has none for it, so the child's isn't taken instead.
     *
     * @return array{costs: array<string, float|null>, mother_sales: array<string, float>}
     */
    public function orderAmounts(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $key = config('services.shecom.product_cost_key');

        if (! $key) {
            throw new RuntimeException('SHECOM_PRODUCT_COST_API_KEY is not set in .env.');
        }

        $response = Http::withToken($key)
            ->acceptJson()
            ->timeout(120)
            ->retry(2, 2000, throw: false)
            ->get(rtrim(config('services.shecom.external_url'), '/').'/orders', [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
            ]);

        if ($response->failed()) {
            throw new RuntimeException("Orders API returned HTTP {$response->status()}: ".$response->json('error', 'unknown error'));
        }

        $costs = [];
        $motherSales = [];

        foreach (collect($response->json('rows') ?? [])->filter(fn (array $row) => ($row['orderId'] ?? '') !== '')->groupBy('orderId') as $id => $rows) {
            $mother = $rows->count() > 1 ? $rows->firstWhere('dept', self::RETENTION_DEPT) : null;

            if ($mother !== null && $rows->contains(fn (array $row) => ($row['dept'] ?? null) !== self::RETENTION_DEPT)) {
                $costs[$id] = is_numeric($mother['productCost'] ?? null) ? (float) $mother['productCost'] : null;

                if (is_numeric($mother['totalSales'] ?? null)) {
                    $motherSales[$id] = (float) $mother['totalSales'];
                }

                continue;
            }

            $row = $rows->last(fn (array $row) => is_numeric($row['productCost'] ?? null));

            if ($row !== null) {
                $costs[$id] = (float) $row['productCost'];
            }
        }

        return ['costs' => $costs, 'mother_sales' => $motherSales];
    }

    /**
     * The retention report's JSON, piece by piece as it downloads.
     *
     * @return Generator<int, string>
     */
    private function download(): Generator
    {
        $key = config('services.shecom.key');

        if (! $key) {
            throw new RuntimeException('SHECOM_API_KEY is not set in .env.');
        }

        // Read as it downloads, the report stays far below this; the limit is kept for the work done with it afterwards.
        $this->raiseMemoryLimit(config('services.shecom.memory_limit'));

        $response = Http::withToken($key)
            ->acceptJson()
            ->timeout(180)
            ->withOptions(['stream' => true])
            ->retry(2, 2000, throw: false)
            ->get(rtrim(config('services.shecom.url'), '/').'/management/retention-stockout');

        if ($response->failed()) {
            throw new RuntimeException("Retention API returned HTTP {$response->status()}: ".$response->json('error', 'unknown error'));
        }

        $body = $response->toPsrResponse()->getBody();

        while (! $body->eof()) {
            yield $body->read(64 * 1024);
        }
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
