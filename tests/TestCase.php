<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

abstract class TestCase extends BaseTestCase
{
    /** @var list<string> Artisan commands pages started in the background, not yet run. */
    private array $backgroundCommands = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Never let a test reach the real retention API (or anything else).
        Http::preventStrayRequests();

        // Pages start syncs as background `php artisan` processes; tests only record them (runBackgroundCommands()).
        Process::fake(function (PendingProcess $process) {
            $this->backgroundCommands[] = $process->command;

            return Process::result();
        });
    }

    /**
     * Run the background Artisan commands pages have started since the last call, as the server would.
     */
    protected function runBackgroundCommands(): void
    {
        while ($command = array_shift($this->backgroundCommands)) {
            preg_match_all("/'([^']*)'/", $command, $quoted);
            // Skip the PHP binary and the artisan path.
            Artisan::call(implode(' ', array_slice($quoted[1], 2)));
        }
    }
}
