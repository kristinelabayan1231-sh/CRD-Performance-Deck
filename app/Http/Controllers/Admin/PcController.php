<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FacebookPage;
use App\Models\Pc;
use App\Services\PancakeService;
use Illuminate\Http\Request;

class PcController extends Controller
{
    public function index()
    {
        $pcs = Pc::with('facebookPage')->orderBy('label')->get();
        $pages = FacebookPage::orderBy('page_name')->get();

        return view('admin.pcs.index', compact('pcs', 'pages'));
    }

    /**
     * JSON list of Pancake accounts active on the given page in the last 30
     * days, for the "Assign page" modal's account dropdown to fetch without
     * a full page reload.
     */
    public function accounts(FacebookPage $facebookPage, PancakeService $pancake)
    {
        try {
            $engagement = $pancake->getEngagement(
                $facebookPage,
                now()->subDays(30)->toDateString(),
                now()->toDateString(),
            );

            $accounts = collect($engagement['users_engagements'] ?? [])
                ->map(fn ($user) => ['id' => $user['user_id'], 'name' => $user['name'] ?? $user['user_id']])
                ->sortBy('name')
                ->values();

            return response()->json(['accounts' => $accounts]);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Could not load Pancake accounts: ' . $e->getMessage()], 422);
        }
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'label' => 'required|string|max:255',
        ]);

        Pc::create($validated);

        return redirect()->route('admin.pcs.index')->with('status', 'PC added. Assign a Facebook page to it below.');
    }

    public function assignPage(Request $request, Pc $pc)
    {
        $validated = $request->validate([
            'facebook_page_id' => 'required|exists:facebook_pages,id',
            'account' => 'required|string',
        ]);

        // The account option value is "pancake_user_id::name".
        [$userId, $userName] = array_pad(explode('::', $validated['account'], 2), 2, null);

        $pc->update([
            'facebook_page_id' => $validated['facebook_page_id'],
            'pancake_user_id' => $userId,
            'pancake_user_name' => $userName,
        ]);

        return redirect()->route('admin.pcs.index')->with('status', "Page assigned to {$pc->label}.");
    }

    public function destroy(Pc $pc)
    {
        $pc->delete();

        return back()->with('status', 'PC removed.');
    }
}
