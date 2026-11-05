<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProductQtyDisplayController extends Controller
{
    public function index(): View
    {
        return view('settings.product-qty', [
            'users' => User::with('role')->orderByDesc('is_active')->orderByRaw('coalesce(display_name, name, email)')->get(),
        ]);
    }

    /**
     * Turn the product qty in a customer's order history on or off for one user.
     */
    public function update(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate(['show_product_qty' => ['required', 'boolean']]);

        $user->update($data);

        return back()->with('status', ($data['show_product_qty'] ? 'Product qty shown' : 'Product qty hidden').' for '.$user->displayName().'.');
    }
}
