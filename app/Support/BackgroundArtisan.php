<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;

use function Illuminate\Support\php_binary;

/**
 * Starts an Artisan command in its own process and returns at once, so a page never runs a long sync itself.
 *
 * A sync run after the response (defer()) still holds one of the web server's few PHP threads for minutes and
 * shares its memory: a few page views at once left no thread for other pages or Render's health check (502).
 */
class BackgroundArtisan
{
    /**
     * Start `php artisan $command`, at most once every $onceSeconds for $onceKey. Returns whether it was started.
     *
     * @param  list<string>  $arguments
     */
    public static function start(string $command, array $arguments, string $onceKey, int $onceSeconds): bool
    {
        if (! Cache::add('background-artisan.'.$onceKey, true, $onceSeconds)) {
            return false;
        }

        $parts = array_map('escapeshellarg', [php_binary(), base_path('artisan'), $command, ...$arguments]);

        // nohup + & detach it, so it keeps running after this request ends.
        Process::path(base_path())->run('nohup '.implode(' ', $parts).' > /dev/null 2>&1 &');

        return true;
    }
}
