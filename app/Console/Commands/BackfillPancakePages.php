<?php

namespace App\Console\Commands;

use App\Services\PancakePageBackfill;
use Illuminate\Console\Command;
use Throwable;

class BackfillPancakePages extends Command
{
    protected $signature = 'pancake:backfill-pages {--minutes=8 : Stop starting new page-months after this long}';

    protected $description = 'Fill in the Pancake page of older orders, one page and month at a time, picking up where the last run stopped';

    public function handle(PancakePageBackfill $backfill): int
    {
        if ($backfill->progress()['complete']) {
            return self::SUCCESS;
        }

        try {
            $backfill->run(now()->toImmutable()->addMinutes((int) $this->option('minutes')), function (string $pageMonth, int $filled) {
                $this->line("{$pageMonth}: {$filled} orders");
            });
        } catch (Throwable $e) {
            // Pancake refuses when hammered; the next scheduled run tries the same page-month again.
            report($e);
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $progress = $backfill->progress();
        $this->info("{$progress['done']} of {$progress['total']} page-months loaded.");

        return self::SUCCESS;
    }
}
