<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class ShecomClient
{
    /**
     * All delivered orders with their estimated out-of-stock date.
     *
     * @return list<array{order_id: string, tracking_number: ?string, customer_name: string, phone_number: string, product_name: string, qty: int, delivered_date: string, consumption_days_per_unit: int, estimated_out_of_stock_date: string}>
     */
    public function retentionStockouts(): array
    {
        $key = config('services.shecom.key');

        if (! $key) {
            throw new RuntimeException('SHECOM_API_KEY is not set in .env.');
        }

        $response = Http::withToken($key)
            ->acceptJson()
            ->timeout(180)
            ->retry(2, 2000, throw: false)
            ->get(rtrim(config('services.shecom.url'), '/').'/management/retention-stockout');

        if ($response->failed()) {
            throw new RuntimeException("Retention API returned HTTP {$response->status()}: ".$response->json('error', 'unknown error'));
        }

        return $response->json('stock_outs', []);
    }
}
