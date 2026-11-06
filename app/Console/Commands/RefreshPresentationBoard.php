<?php

namespace App\Console\Commands;

use App\Services\PresentationBoard;
use Illuminate\Console\Command;

class RefreshPresentationBoard extends Command
{
    protected $signature = 'presentation-board:refresh';

    protected $description = 'Take the Presentation Board snapshot of the year so far';

    public function handle(PresentationBoard $board): int
    {
        $board->refresh();
        $this->info('Presentation Board refreshed.');

        return self::SUCCESS;
    }
}
