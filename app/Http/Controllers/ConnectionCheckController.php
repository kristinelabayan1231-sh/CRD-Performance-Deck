<?php

namespace App\Http\Controllers;

use App\Services\ConnectionChecker;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class ConnectionCheckController extends Controller
{
    public function index(): View
    {
        return view('settings.connections', [
            'results' => session('connection_results'),
            // Sessions are stored as JSON, so the time comes back as a string.
            'checkedAt' => session('connection_checked_at') ? CarbonImmutable::parse(session('connection_checked_at')) : null,
            'usesAccessToken' => (bool) config('services.pancake.access_token'),
        ]);
    }

    /**
     * Run every check on this server; takes about 10–20 seconds.
     */
    public function run(ConnectionChecker $checker): RedirectResponse
    {
        return back()
            ->with('connection_results', $checker->run())
            ->with('connection_checked_at', now()->toIso8601String());
    }
}
