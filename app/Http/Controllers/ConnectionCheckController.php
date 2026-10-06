<?php

namespace App\Http\Controllers;

use App\Services\ConnectionChecker;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class ConnectionCheckController extends Controller
{
    public function index(): View
    {
        return view('settings.connections', [
            'results' => session('connection_results'),
            'checkedAt' => session('connection_checked_at'),
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
            ->with('connection_checked_at', now());
    }
}
