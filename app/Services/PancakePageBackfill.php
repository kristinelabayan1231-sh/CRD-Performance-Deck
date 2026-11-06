<?php

namespace App\Services;

use App\Models\PancakeOrder;
use App\Models\PancakePage;
use App\Models\Setting;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Fills in pancake_orders.page_id for older orders, which were saved without it, one Settings → Pancake Pages page and
 * month at a time ("page-month"). Only empty page IDs are written. A page's months before its counts_from are skipped:
 * those orders don't count for that page anyway. Done page-months are remembered, so a run picks up where the last stopped.
 */
class PancakePageBackfill
{
    public const SETTING = 'pancake.page_backfill_done';

    /** The months missing page IDs; from November 2026 the sync saves them. */
    public const FIRST_MONTH = '2025-06';

    public const LAST_MONTH = '2026-10';

    public function __construct(private PancakeClient $client) {}

    /**
     * @return array{done: int, total: int, complete: bool}
     */
    public function progress(): array
    {
        $done = array_flip($this->done());
        $tasks = $this->tasks();
        $finished = $tasks->filter(fn (array $task) => isset($done[$task['key']]))->count();

        return ['done' => $finished, 'total' => $tasks->count(), 'complete' => $tasks->isNotEmpty() && $finished === $tasks->count()];
    }

    /**
     * The months (Y-m) every page has finished: an order there still without a page ID is on a page not in the list.
     *
     * @return array<string, true>
     */
    public function completeMonths(): array
    {
        $done = array_flip($this->done());

        return $this->tasks()
            ->groupBy('month')
            ->filter(fn (Collection $tasks) => $tasks->every(fn (array $task) => isset($done[$task['key']])))
            ->map(fn () => true)
            ->all();
    }

    /**
     * Load page-months until $stopAt. A Pancake failure ends the run; the next run tries that page-month again.
     *
     * @param  (callable(string, int): void)|null  $onDone  told each page-month and how many orders it filled
     * @return int page-months loaded
     */
    public function run(CarbonImmutable $stopAt, ?callable $onDone = null): int
    {
        $done = $this->done();
        $finished = array_flip($done);
        $loaded = 0;

        foreach ($this->tasks() as $task) {
            if (isset($finished[$task['key']])) {
                continue;
            }
            if (now()->greaterThanOrEqualTo($stopAt)) {
                break;
            }

            $filled = 0;
            foreach (array_chunk($this->client->orderNumbersOnPage($task['page_id'], CarbonImmutable::parse($task['month'].'-01')), 500) as $numbers) {
                // Not through the model: updated_at says when the order last changed in Pancake's sync.
                $filled += PancakeOrder::query()->toBase()->whereIn('pancake_order_id', $numbers)->whereNull('page_id')->update(['page_id' => $task['page_id']]);
            }

            $done[] = $task['key'];
            Setting::put(self::SETTING, json_encode($done));
            $loaded++;

            if ($onDone) {
                $onDone($task['key'], $filled);
            }
        }

        return $loaded;
    }

    /**
     * @return list<string>
     */
    private function done(): array
    {
        return json_decode((string) Setting::value(self::SETTING, '[]'), true) ?: [];
    }

    /**
     * @return Collection<int, array{key: string, page_id: string, month: string}>
     */
    private function tasks(): Collection
    {
        $pages = PancakePage::whereNotNull('page_id')->orderBy('id')->get(['page_id', 'counts_from']);
        $tasks = collect();

        for ($month = CarbonImmutable::parse(self::FIRST_MONTH.'-01'); $month->format('Y-m') <= self::LAST_MONTH; $month = $month->addMonth()) {
            foreach ($pages as $page) {
                if ($page->counts_from && $month->endOfMonth()->lessThan($page->counts_from)) {
                    continue;
                }

                $tasks->push(['key' => $page->page_id.'-'.$month->format('Y-m'), 'page_id' => (string) $page->page_id, 'month' => $month->format('Y-m')]);
            }
        }

        return $tasks;
    }
}
