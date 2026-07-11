<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PosCredential;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class PosCredentialController extends Controller
{
    public function store(Request $request)
    {
        $validated = $request->validate([
            'shop_id' => 'required|string',
            'api_key' => 'required|string',
        ]);

        // Verify the shop_id + api_key pair actually works against Pancake's POS API
        $response = Http::get("https://pos.pages.fm/api/v1/shops/{$validated['shop_id']}/orders", [
            'api_key' => $validated['api_key'],
            'page_size' => 1,
        ]);

        if ($response->failed() || ! ($response->json('success') ?? true)) {
            return back()
                ->withErrors('Could not verify this shop with Pancake POS. Double-check the Shop ID and API Key.')
                ->withInput();
        }

        // Single shop covers every page on the account — replace, don't accumulate.
        PosCredential::where('shop_id', '!=', $validated['shop_id'])->delete();
        PosCredential::updateOrCreate(['shop_id' => $validated['shop_id']], $validated);

        return back()->with('status', 'POS credentials saved.');
    }

    public function destroy(PosCredential $posCredential)
    {
        $posCredential->delete();

        return back()->with('status', 'POS credentials removed.');
    }
}
