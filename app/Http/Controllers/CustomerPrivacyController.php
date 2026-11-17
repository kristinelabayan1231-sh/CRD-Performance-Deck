<?php

namespace App\Http\Controllers;

use App\Support\CustomerPrivacy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CustomerPrivacyController extends Controller
{
    /**
     * The Customer Database eye button: hide or show customers' names and numbers for this session.
     */
    public function __invoke(Request $request): RedirectResponse
    {
        $request->session()->put(CustomerPrivacy::SESSION_KEY, ! $request->session()->get(CustomerPrivacy::SESSION_KEY, false));

        return back();
    }
}
