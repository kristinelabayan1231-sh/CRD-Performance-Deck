<?php

namespace App\Http\Controllers;

use App\Models\ExclusiveFsaAccount;
use App\Models\PancakeOrder;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;

/**
 * Exclusive Lead Tracker → Settings: the FSA Pancake accounts whose customers belong to an exclusive CRA while no CRA
 * has sold to them (ExclusiveFsaCustomers). A change applies from the next list build; leads already handed out stay.
 */
class ExclusiveFsaAccountController extends Controller
{
    /** How long the list of Pancake seller accounts to pick from is kept. */
    private const SELLERS_SECONDS = 3600;

    public function store(Request $request, User $user): RedirectResponse
    {
        abort_if($user->exclusive_from === null, 422, "{$user->displayName()} is not on the Exclusive Lead Tracker.");

        $data = $request->validate([
            'seller_name' => ['required', 'string', 'max:255', Rule::in(self::sellerNames())],
        ], ['seller_name.in' => 'Pick an FSA account from the list: it has to be a Pancake seller that is not a CRD account.']);

        ExclusiveFsaAccount::firstOrCreate(
            ['user_id' => $user->id, 'seller_name' => $data['seller_name']],
            ['set_by' => $request->user()->id],
        );

        return back()->with('status', "{$data['seller_name']}'s customers now go to {$user->displayName()} while no CRA has handled them.");
    }

    public function destroy(ExclusiveFsaAccount $account): RedirectResponse
    {
        $account->delete();

        return back()->with('status', "{$account->seller_name}'s customers no longer go to {$account->user->displayName()}. Leads already on her list stay.");
    }

    /**
     * Pancake seller accounts that aren't CRD accounts, A–Z.
     *
     * @return list<string>
     */
    public static function sellerNames(): array
    {
        $names = Cache::remember('exclusive-leads.fsa-seller-names', self::SELLERS_SECONDS, fn () => PancakeOrder::query()
            ->whereNotNull('seller_name')->where('seller_name', '!=', '')
            ->distinct()->orderBy('seller_name')->pluck('seller_name')->all());

        return array_values(array_diff($names, PancakeOrder::crdAccounts()));
    }
}
