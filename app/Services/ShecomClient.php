<?php

namespace App\Services;

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
        $report = $this->report();
        LogisticsRetention::store($report);

        return $report['stock_outs'];
    }

    /**
     * The retention report, with its large per-order retention lists reduced to
     * daily counts (by delivered date) as soon as it is read.
     *
     * @return array{stock_outs: list<array<string, mixed>>, summary: array<string, int|float>, days: array<string, array{fb_delivered: int, fb_retained: int, crd_delivered: int, crd_again: int}>}
     */
    public function report(): array
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

        $body = json_decode($response->body(), true) ?: [];
        unset($response);

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
            'summary' => $body['retention_summary'] ?? [],
            'days' => $days,
        ];
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
