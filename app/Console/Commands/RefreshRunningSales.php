<?php

namespace App\Console\Commands;

use App\Services\RunningSales;
use Illuminate\Console\Command;

class RefreshRunningSales extends Command
{
    protected $signature = 'running-sales:refresh';

    protected $description = 'Take the Running Sales snapshot (today, this week, this month) from the saved Pancake orders';

    public function handle(RunningSales $runningSales): int
    {
        $runningSales->refresh();
        $this->info('Running Sales refreshed.');

        return self::SUCCESS;
    }
}
