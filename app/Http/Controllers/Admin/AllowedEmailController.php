<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AllowedEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AllowedEmailController extends Controller
{
    public function index(): View
    {
        return view('admin.emails.index', [
            'emails' => AllowedEmail::orderBy('email')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255', 'unique:allowed_emails,email'],
            'is_admin' => ['sometimes', 'boolean'],
        ]);

        AllowedEmail::create([
            'email' => strtolower($validated['email']),
            'is_admin' => $request->boolean('is_admin'),
        ]);

        return back()->with('status', "{$validated['email']} can now sign in.");
    }

    public function destroy(AllowedEmail $allowedEmail): RedirectResponse
    {
        if ($allowedEmail->is_admin && AllowedEmail::where('is_admin', true)->count() <= 1) {
            return back()->withErrors(['email' => 'You cannot remove the last remaining admin.']);
        }

        $allowedEmail->delete();

        return back()->with('status', "{$allowedEmail->email} no longer has access.");
    }
}
