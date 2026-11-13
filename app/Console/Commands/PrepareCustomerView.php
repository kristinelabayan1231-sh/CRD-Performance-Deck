<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\CustomerDatabase;
use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * Works out a Customer Database / High AOV & VIP / Churn view in the background (started by CustomerDatabase when a
 * page asks for one that isn't cached): it opens the same page as the same user, which fills the cache, so the page
 * shows exactly what it would have worked out itself.
 */
class PrepareCustomerView extends Command
{
    protected $signature = 'customers:prepare-view {path : The page path with its query string} {user : The id of the user who asked}';

    protected $description = 'Work out a Customer Database view in the background so the page opens from the cache';

    public function handle(Kernel $kernel): int
    {
        $user = User::find((int) $this->argument('user'));
        $path = (string) $this->argument('path');

        if ($user === null || ! str_starts_with($path, '/customers')) {
            return self::FAILURE;
        }

        $started = microtime(true);
        CustomerDatabase::$preparing = true;
        $request = Request::create($path, 'GET');
        $this->laravel->instance('request', $request);
        Auth::guard('web')->setUser($user);

        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);

        $summary = "{$path} ready in ".round(microtime(true) - $started, 1).'s (HTTP '.$response->getStatusCode().').';
        $this->info($summary);
        Log::info("customers:prepare-view {$summary}");

        return $response->isSuccessful() ? self::SUCCESS : self::FAILURE;
    }
}
