<?php

namespace App\Http\Controllers;

use App\Models\ApiKey;
use App\Services\RetentionDaily;
use App\Support\MonthlyCost;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Settings → API Keys: make a key for each team or system that reads the external API, for one scope (retention daily
 * figures, or MC / salaries), and revoke it when it is no longer needed. A new key is shown once.
 */
class ApiKeyController extends Controller
{
    public function index(MonthlyCost $cost): View
    {
        return view('settings.api-keys', [
            'keys' => ApiKey::with('creator')->latest('id')->get(),
            'endpoint' => route('api.external.retention.daily'),
            'maxDays' => RetentionDaily::MAX_DAYS,
            'mcEndpoints' => ['summary' => route('api.external.mc.summary'), 'roster' => route('api.external.mc.roster')],
            'mcCost' => $cost,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'scope' => ['required', Rule::in(array_keys(ApiKey::SCOPES))],
        ], ['scope.required' => 'Choose what the key may read.']);

        [$key, $token] = ApiKey::issue($validated['name'], $request->user(), $validated['scope']);

        return redirect()->route('settings.api-keys.index')->with('new_key', ['name' => $key->name, 'scope' => ApiKey::SCOPES[$key->scope], 'token' => $token]);
    }

    public function destroy(ApiKey $apiKey): RedirectResponse
    {
        $apiKey->forceFill(['revoked_at' => now()])->save();

        return redirect()->route('settings.api-keys.index')->with('status', "\"{$apiKey->name}\" is revoked: it no longer works.");
    }
}
