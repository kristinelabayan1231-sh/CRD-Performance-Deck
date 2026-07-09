<?php

namespace App\Services;

use App\Models\FacebookPage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

class PancakeService
{
    protected string $baseV1 = 'https://pages.fm/api/public_api/v1';
    protected string $baseV2 = 'https://pages.fm/api/public_api/v2';
    
    public function getInboxInquiries(FacebookPage $page, string $startDate, string $endDate): \Illuminate\Support\Collection
    {
        $since = Carbon::parse($startDate)->startOfDay()->timestamp;
        $until = Carbon::parse($endDate)->endOfDay()->timestamp;

        $conversations = collect();
        $lastConversationId = null;

        do {
            $query = array_filter([
                'page_access_token' => $page->access_token,
                'order_by' => 'inserted_at',
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

            $batch = collect($response->json('conversations', []));
            $conversations = $conversations->concat($batch->where('type', 'INBOX'));

            $lastConversationId = $batch->count() === 60 ? $batch->last()['id'] : null;
        } while ($lastConversationId);

        return $conversations->values();
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