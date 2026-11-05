<?php

namespace App\Http\Controllers;

use App\Models\PancakeEngagement;
use App\Models\Setting;
use App\Models\User;
use App\Services\CustomerDatabase;
use App\Support\PancakeAccounts;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PancakeAccountController extends Controller
{
    public function index(CustomerDatabase $customers): View
    {
        $craUsers = PancakeAccounts::craUsers();

        return view('settings.pancake-accounts', [
            'crdAccounts' => PancakeAccounts::crd(),
            'craUsers' => $craUsers,
            'aliases' => PancakeAccounts::aliasesByCra($craUsers),
            'aliasesChange' => Setting::where('key', PancakeAccounts::ALIASES)->with('editor')->first(),
            'history' => Cache::remember('customers.history_progress', now()->addMinutes(CustomerDatabase::CACHE_MINUTES), fn () => $customers->historyProgress()),
            'recheckFrom' => PancakeAccounts::historyRecheckFrom(),
            'lastChange' => Setting::where('key', PancakeAccounts::CRD)->with('editor')->first(),
        ]);
    }

    /**
     * Save the CRD team accounts; the POS deliveries before the logistics report are relabeled with them
     * and customers delivered in the past month get their earlier orders checked again.
     */
    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'accounts' => ['nullable', 'array', 'max:200'],
            'accounts.*' => ['nullable', 'string', 'max:100'],
        ], [], ['accounts.*' => 'account name']);

        $relabeled = PancakeAccounts::saveCrd($data['accounts'] ?? [], $request->user());

        return back()->with('status', 'CRD team accounts saved.'.($relabeled ? " {$relabeled} older ".str('delivery')->plural($relabeled).' relabeled CRD / FSD.' : '')
            .' Customers delivered in the past month get their orders before '.CarbonImmutable::parse(config('customers.delivered_from'))->format('M j, Y').' checked again in the background.');
    }

    /**
     * Save each CRA's other accounts. One account can belong to one CRA only, and never be another CRA's own account.
     */
    public function updateAliases(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'aliases' => ['nullable', 'array'],
            'aliases.*' => ['nullable', 'array', 'max:20'],
            'aliases.*.*' => ['nullable', 'string', 'max:100'],
        ], [], ['aliases.*.*' => 'account name']);

        $cras = PancakeAccounts::craUsers();
        $owners = $cras->mapWithKeys(fn (User $cra) => [PancakeEngagement::staffKey($cra->pancake_name) => $cra]);
        $taken = [];

        foreach ($cras as $cra) {
            foreach ($data['aliases'][$cra->id] ?? [] as $name) {
                $key = PancakeEngagement::staffKey($name);
                if ($key === '' || $key === PancakeEngagement::staffKey($cra->pancake_name)) {
                    continue;
                }
                if (isset($owners[$key])) {
                    throw ValidationException::withMessages(['aliases' => "{$name} is {$owners[$key]->displayName()}'s own account."]);
                }
                if (isset($taken[$key]) && $taken[$key] !== $cra->id) {
                    throw ValidationException::withMessages(['aliases' => "{$name} is listed under two CRAs. Keep it under one."]);
                }
                $taken[$key] = $cra->id;
            }
        }

        PancakeAccounts::saveAliases($cras, $data['aliases'] ?? [], $request->user());

        return back()->with('status', 'CRA accounts saved.');
    }
}
