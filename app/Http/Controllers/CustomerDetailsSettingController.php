<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Support\CustomerPrivacy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Settings → Customer Database: whether customers' names and numbers show to every role (hidden by default).
 */
class CustomerDetailsSettingController extends Controller
{
    public function index(CustomerPrivacy $privacy): View
    {
        return view('settings.customer-details', [
            'shownToAll' => $privacy->shownToAll(),
            'lastChange' => Setting::where('key', CustomerPrivacy::SHOWN_TO_ALL_KEY)->with('editor')->first(),
        ]);
    }

    /**
     * Show the details to every role, or hide them again. The manager's own eye-button choice resets to the new setting.
     */
    public function update(Request $request): RedirectResponse
    {
        $shown = $request->validate(['shown_to_all' => ['required', 'boolean']])['shown_to_all'];

        Setting::put(CustomerPrivacy::SHOWN_TO_ALL_KEY, $shown ? '1' : '0', $request->user());
        $request->session()->forget(CustomerPrivacy::SESSION_KEY);
        // The setting is remembered for the request; the next one reads the new value.
        app()->forgetInstance(CustomerPrivacy::class);

        return back()->with('status', $shown
            ? 'Customers\' names and numbers now show to every role. Anyone can still hide them with the eye button.'
            : 'Customers\' names and numbers are now hidden for every role (VIP 1, High AOV 1, Kr**** L***).');
    }
}
