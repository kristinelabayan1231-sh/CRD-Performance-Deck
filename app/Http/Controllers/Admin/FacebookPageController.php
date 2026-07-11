<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FacebookPage;
use App\Models\PosCredential;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class FacebookPageController extends Controller
{
    public function index()
    {
        $pages = FacebookPage::latest()->get();
        $posCredential = PosCredential::current();

        return view('admin.facebook-pages.index', compact('pages', 'posCredential'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'page_id' => 'required|string|unique:facebook_pages,page_id',
            'page_name' => 'required|string',
            'access_token' => 'required|string',
        ]);

        // Verify the page_id + access_token pair actually works against Pancake
        $response = Http::get("https://pages.fm/api/public_api/v2/pages/{$validated['page_id']}/conversations", [
            'page_access_token' => $validated['access_token'],
            'page_size' => 1,
        ]);

        if ($response->failed()) {
            return back()
                ->withErrors('Could not verify this page with Pancake. Double-check the Page ID and Access Token.')
                ->withInput();
        }

        FacebookPage::create($validated);

        return back()->with('status', 'Facebook page added.');
    }

    public function destroy(FacebookPage $facebookPage)
    {
        $facebookPage->delete();

        return back()->with('status', 'Facebook page removed.');
    }
}