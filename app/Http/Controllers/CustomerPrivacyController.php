<?php

namespace App\Http\Controllers;

use App\Support\CustomerPrivacy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CustomerPrivacyController extends Controller
{
    /**
     * The Customer Database eye button: hide or show customers' names and numbers for this session (showing only
     * where CustomerPrivacy::canReveal()).
     * From the customer pop-up (JSON) it answers whether they're now hidden, so the pop-up reloads in place.
     */
    public function __invoke(Request $request, CustomerPrivacy $privacy): JsonResponse|RedirectResponse
    {
        // While hidden for every role, only a Super Admin or CRA Supervisor can show them (for themselves).
        if ($privacy->canReveal()) {
            $request->session()->put(CustomerPrivacy::SESSION_KEY, ! $privacy->isHidden());
        }
        $hidden = $privacy->isHidden();

        return $request->expectsJson() ? response()->json(['hidden' => $hidden]) : back();
    }
}
