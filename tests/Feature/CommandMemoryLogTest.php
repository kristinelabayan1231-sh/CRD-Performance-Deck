<?php

namespace Tests\Feature;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Tests\TestCase;

class CommandMemoryLogTest extends TestCase
{
    /**
     * The console kernel sends CommandFinished only for real command-line runs, so the tests send it themselves.
     */
    private function finish(string $command, array $options = []): void
    {
        event(new CommandFinished($command, new ArrayInput(['command' => $command, ...$options]), new NullOutput, 0));
    }

    public function test_a_command_at_or_over_the_threshold_logs_its_peak_memory(): void
    {
        config(['logging.command_memory_mb' => 0]);
        Log::spy();

        $this->finish('pancake:sync', ['--new' => null]);

        Log::shouldHaveReceived('info')->with(\Mockery::pattern('/^artisan pancake:sync --new: peak memory \d+ MB$/'))->once();
    }

    public function test_a_command_under_the_threshold_logs_nothing(): void
    {
        config(['logging.command_memory_mb' => 100000]);
        Log::spy();

        $this->finish('pancake:sync', ['--new' => null]);

        Log::shouldNotHaveReceived('info');
    }
}
