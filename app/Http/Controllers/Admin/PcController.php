<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FacebookPage;
use App\Models\Pc;
use App\Services\PancakeService;
use Illuminate\Http\Request;

class PcController extends Controller
{
    public function index(Request $request, PancakeService $pancake)
    {
        $pcs = Pc::with('facebookPage')->orderBy('label')->get();
        $pages = FacebookPage::orderBy('page_name')->get();

        // "Assign page" flow: ?pc_id opens the panel for that PC; adding
        // &page_id loads that page's Pancake accounts (seen in the last 30
        // days of engagement stats) to pick from.
        $assigningPc = $request->query('pc_id') ? $pcs->firstWhere('id', (int) $request->query('pc_id')) : null;
        $selectedPage = $request->query('page_id') ? $pages->firstWhere('id', (int) $request->query('page_id')) : null;
        $accountOptions = [];
        $accountsError = null;

        if ($assigningPc && $selectedPage) {
            try {
                $engagement = $pancake->getEngagement(
                    $selectedPage,
                    now()->subDays(30)->toDateString(),
                    now()->toDateString(),
                );

                $accountOptions = collect($engagement['users_engagements'] ?? [])
                    ->map(fn ($user) => ['id' => $user['user_id'], 'name' => $user['name'] ?? $user['user_id']])
                    ->sortBy('name')
                    ->values()
                    ->all();
            } catch (\Throwable $e) {
                $accountsError = 'Could not load Pancake accounts: ' . $e->getMessage();
            }
        }

        return view('admin.pcs.index', compact('pcs', 'pages', 'assigningPc', 'selectedPage', 'accountOptions', 'accountsError'));
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
