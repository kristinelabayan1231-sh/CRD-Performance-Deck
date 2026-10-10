<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Services\CustomerDatabase;
use App\Support\PancakeAccounts;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

class PancakeAccountController extends Controller
{
    public function index(CustomerDatabase $customers): View
    {
        return view('settings.pancake-accounts', [
            'crdAccounts' => PancakeAccounts::crd(),
            'craUsers' => PancakeAccounts::craUsers(),
            'history' => Cache::remember('customers.history_progress', now()->addMinutes(5), fn () => $customers->historyProgress()),
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
}
