<?php

namespace App\Console\Commands;

use App\Models\PcReportRow;
use App\Services\PcInquiries;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

class SyncPcReportRow extends Command
{
    protected $signature = 'pc-report:sync {row : The PC Report row (pc_report_rows id)}';

    protected $description = 'Fetch a PC Report row\'s inquiries (conversations of its days not saved yet) and engagements, with progress for its Sync bar';

    public function handle(PcInquiries $inquiries): int
    {
        $row = PcReportRow::find($this->argument('row'));
        $page = $row ? PcInquiries::pages()->get(strtoupper($row->page)) : null;

        if (! $row || ! $page || $row->from_month === null) {
            $this->error('No such row with a Creation/Update Time, or its page is not in Settings → Pancake Pages.');

            return self::FAILURE;
        }

        // One sync per page at a time; a run cut off by a restart frees the page after a few minutes.
        $busy = PcReportRow::pageBusyKey($page->id);
        if (Cache::get($busy) !== null && Cache::get($busy) !== $row->id) {
            $this->error('Another row of this page is syncing.');

            return self::FAILURE;
        }

        Cache::put($busy, $row->id, PcReportRow::BUSY_SECONDS);

        try {
            $inquiries->syncRow($row, $page, function (int $done, int $total) use ($row, $busy) {
                Cache::put($busy, $row->id, PcReportRow::BUSY_SECONDS);
                $row->putProgress(['status' => 'running', 'done' => $done, 'total' => $total]);
                $this->info("{$row->page}: {$done}/{$total} days");
            });
            $row->putProgress(['status' => 'done', 'done' => 1, 'total' => 1]);
            $this->info("{$row->page}: done, {$row->fresh()->engagements} engagements.");

            return self::SUCCESS;
        } catch (Throwable $e) {
            $row->putProgress(['status' => 'failed', 'message' => $e->getMessage()]);
            $this->error("{$row->page}: {$e->getMessage()}");

            return self::FAILURE;
        } finally {
            Cache::forget($busy);
        }
    }
}
