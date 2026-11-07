<?php

namespace App\Console\Commands;

use App\Services\PresentationBoardCras;
use Illuminate\Console\Command;

class RefreshPresentationBoardCras extends Command
{
    protected $signature = 'presentation-board:refresh-cras';

    protected $description = "Take the Presentation Board's snapshot of each CRA's year so far";

    public function handle(PresentationBoardCras $cras): int
    {
        $cras->refresh();
        $this->info('Presentation Board CRA performance refreshed.');

        return self::SUCCESS;
    }
}
