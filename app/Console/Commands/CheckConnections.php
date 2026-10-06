<?php

namespace App\Console\Commands;

use App\Services\ConnectionChecker;
use Illuminate\Console\Command;

class CheckConnections extends Command
{
    protected $signature = 'connections:check';

    protected $description = 'Test the database, Shecom and Pancake connections with one small request each';

    public function handle(ConnectionChecker $checker): int
    {
        $rows = $checker->run();

        $this->table(['Connection', 'Result', 'Details', 'Time'], array_map(
            fn (array $row) => [$row['name'], $row['ok'] ? 'OK' : 'FAIL', $row['details'], $row['seconds'].'s'],
            $rows,
        ));

        return collect($rows)->contains('ok', false) ? self::FAILURE : self::SUCCESS;
    }
}
