<?php

namespace App\Console\Commands;

use App\Models\Cra;
use App\Models\CraPcDayStat;
use App\Models\Pc;
use App\Models\PcDayStat;
use App\Models\PosCredential;
use App\Models\WeeklyConversationTag;
use App\Services\PancakeService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class SyncCraStats extends Command
{
    protected $signature = 'pancake:sync-cra-stats
        {--date= : Last day to sync (Y-m-d, defaults to today)}
        {--days=1 : How many days back from --date to sync}';

    protected $description = 'Pull per-PC engagement/orders and cohort-bucketed inquiries from Pancake into the local stats tables';

    public function handle(PancakeService $pancake): int
    {
        $endDate = Carbon::parse($this->option('date') ?? now()->toDateString())->startOfDay();
        $days = max(1, (int) $this->option('days'));

        $pcs = Pc::with('facebookPage')
            ->whereNotNull('facebook_page_id')
            ->whereNotNull('pancake_user_id')
            ->get();

        if ($pcs->isEmpty()) {
            $this->warn('No PCs with a Facebook page assigned — nothing to sync.');

            return self::SUCCESS;
        }

        $cras = Cra::with('pcAssignments')->get();
        $pcsByPage = $pcs->groupBy('facebook_page_id');
        $posCredential = PosCredential::current();

        if (! $posCredential) {
            $this->warn('No POS credentials set — inquiries will not be filtered by Order Status (Delivered). Set them under Admin → Facebook Pages.');
        }

        for ($i = $days - 1; $i >= 0; $i--) {
            $date = $endDate->copy()->subDays($i);
            $this->info("Syncing {$date->toDateString()}…");

            // One shop covers every page, so this is resolved once per day
            // and reused across all pages below rather than refetched per page.
            $deliveredConversationIds = $posCredential
                ? $pancake->deliveredConversationIds($posCredential->shop_id, $posCredential->api_key, $date->toDateString())
                : null;

            foreach ($pcsByPage as $pagePcs) {
                $page = $pagePcs->first()->facebookPage;

                try {
                    $this->syncPageDay($pancake, $page, $pagePcs, $cras, $date, $deliveredConversationIds);
                } catch (\Throwable $e) {
                    $this->error("  {$page->page_name}: {$e->getMessage()}");
                }
            }
        }

        return self::SUCCESS;
    }

    protected function syncPageDay(PancakeService $pancake, $page, $pagePcs, $cras, Carbon $date, ?array $deliveredConversationIds): void
    {
        // --- PC-level engagement and orders, from users_engagements ---
        $engagement = $pancake->getEngagement($page, $date->toDateString(), $date->toDateString());
        $usersEngagements = collect($engagement['users_engagements'] ?? [])->keyBy('user_id');

        foreach ($pagePcs as $pc) {
            $userStats = $usersEngagements->get($pc->pancake_user_id);

            PcDayStat::updateOrCreate(
                ['pc_id' => $pc->id, 'date' => $date->toDateString()],
                [
                    'engagement' => $userStats['total_engagement'] ?? 0,
                    'orders' => $userStats['order_count'] ?? 0,
                ],
            );
        }

        // --- CRA-level inquiries, bucketed by customer-creation cohort ---
        // Resolve which (CRA, PC, cohort) combinations were explicitly set
        // for the 7-day block $date falls in — a CRA with nothing entered
        // for this week is simply skipped (no row written), which is what
        // lets the "set your cohort" prompt detect it as missing.
        // Cohort bounds are precomputed as ISO strings so each of the
        // (potentially tens of thousands of) conversations is bucketed with
        // plain string comparisons instead of Carbon parsing.
        $buckets = [];
        foreach ($cras as $cra) {
            foreach ($cra->assignmentsForWeek($date) as $assignment) {
                // "No cohort this week" rows are an explicit opt-out — no
                // creation window to bucket inquiries into.
                if (! $assignment->hasCohort()) {
                    continue;
                }

                if ($pagePcs->contains('id', $assignment->pc_id)) {
                    $buckets[] = [
                        'assignment' => $assignment,
                        'from' => $assignment->cohortStart()->format('Y-m-d\TH:i:s'),
                        'to' => $assignment->cohortEnd()->format('Y-m-d\TH:i:s'),
                        'count' => 0,
                    ];
                }
            }
        }

        if ($buckets === []) {
            return;
        }

        // Mirrors Pancake's own "Total Inquiries" filter: only conversations
        // with a phone number, not tagged BLOCKED, not carrying this week's
        // Conversation Tag (a fresh tag CRD creates in Pancake every 7-day
        // block to mark conversations to exclude — it's a weekly exclude
        // filter, not an include one), and whose linked order's current
        // status is "Delivered" count. The API silently ignores tag/
        // has_phone query params on this endpoint, so those two are
        // applied client-side per streamed conversation using the tags/
        // has_phone fields it already returns. Order Status comes from a
        // separate POS API lookup (order status isn't exposed on the
        // conversation object at all) done once per day per shop by the
        // caller — a null set here means no POS credentials are
        // configured, so that check is skipped rather than silently
        // zeroing every inquiry out.
        $excludedTag = WeeklyConversationTag::forWeek($date)?->tag_name;

        // A PC "owns" its assigned customer-creation cohort — any
        // conversation from a customer in that cohort counts as its
        // inquiry, regardless of who the thread is assigned to in
        // Pancake's inbox (assignees are unrelated to PC accounts).
        $processed = $pancake->eachInboxConversation(
            $page,
            $date->toDateString(),
            $date->toDateString(),
            function (array $conversation) use (&$buckets, $excludedTag, $deliveredConversationIds) {
                if (($conversation['has_phone'] ?? false) !== true) {
                    return;
                }

                $tagTexts = collect($conversation['tags'] ?? [])
                    ->filter()
                    ->map(fn ($tag) => trim($tag['text'] ?? ''));

                if ($tagTexts->contains(fn ($text) => strcasecmp($text, 'BLOCKED') === 0)) {
                    return;
                }

                if ($excludedTag && $tagTexts->contains(fn ($text) => strcasecmp($text, $excludedTag) === 0)) {
                    return;
                }

                if ($deliveredConversationIds !== null && ! isset($deliveredConversationIds[$conversation['id']])) {
                    return;
                }

                $customerCreatedAt = $conversation['page_customer']['inserted_at'] ?? null;

                if (! $customerCreatedAt) {
                    return;
                }

                foreach ($buckets as $j => $bucket) {
                    if ($customerCreatedAt >= $bucket['from'] && $customerCreatedAt <= $bucket['to']) {
                        $buckets[$j]['count']++;
                    }
                }
            },
        );

        foreach ($buckets as $bucket) {
            $assignment = $bucket['assignment'];

            // Only touch inquiries — amount/tagging on the same row are human-entered.
            CraPcDayStat::updateOrCreate(
                [
                    'cra_id' => $assignment->cra_id,
                    'pc_id' => $assignment->pc_id,
                    'date' => $date->toDateString(),
                ],
                ['inquiries' => $bucket['count']],
            );
        }

        $this->line("  {$page->page_name}: {$pagePcs->count()} PCs, {$processed} conversations");
    }
}
