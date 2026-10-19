<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Models\Role;
use App\Services\BacklogFill;
use App\Services\LeadGenerator;
use App\Support\OnDuty;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class OnDutyController extends Controller
{
    /**
     * A CRA logs in from the Segmentation Tracker: they're on today's on-duty list, and once today's leads
     * are in, their day is topped up with backlog (BacklogFill; the hourly lead sync does it otherwise).
     */
    public function store(Request $request, BacklogFill $fill): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->role?->slug === Role::CRA, 403);

        $login = OnDuty::logIn($user);
        $today = Lead::today();

        if (LeadGenerator::lastSync($today)) {
            $fill->fill($today, collect([$user]));
        }

        return back()->with('status', "You're on duty for today ({$login->date->format('D, M j')}), logged in at {$login->logged_in_at->timezone(config('segmentation.timezone'))->format('g:i A')}.");
    }
}
