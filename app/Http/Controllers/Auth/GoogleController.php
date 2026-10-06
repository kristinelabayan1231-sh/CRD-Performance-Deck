<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

class GoogleController extends Controller
{
    public function showLogin(): View
    {
        return view('auth.login');
    }

    public function redirect(Request $request): RedirectResponse
    {
        $request->session()->put('login.remember', $request->boolean('remember'));

        return Socialite::driver('google')
            ->with(['prompt' => 'select_account'])
            ->redirect();
    }

    public function callback(Request $request): RedirectResponse
    {
        if ($request->has('error')) {
            return $this->deny('Google sign-in was cancelled.');
        }

        try {
            $googleUser = Socialite::driver('google')->user();
        } catch (Throwable $e) {
            Log::warning('Google sign-in failed', ['message' => $e->getMessage()]);

            return $this->deny('Google sign-in failed. Please try again.');
        }

        $email = strtolower((string) $googleUser->getEmail());

        if ($email === '' || ($googleUser->user['email_verified'] ?? true) === false) {
            return $this->deny('Your Google account email is not verified.');
        }

        $user = User::where('email', $email)->first();

        if (! $user && $email === config('access.super_admin_email')) {
            $user = User::create([
                'email' => $email,
                'role_id' => Role::superAdmin()->id,
                'is_active' => true,
            ]);
        }

        if (! $user) {
            return $this->deny("{$email} doesn't have access. Ask a super admin to add you in User Access.");
        }

        if ($user->isOwner()) {
            $user->forceFill(['role_id' => Role::superAdmin()->id, 'is_active' => true]);
        }

        if (! $user->is_active) {
            return $this->deny('Your access has been disabled. Contact a super admin.');
        }

        $user->forceFill([
            'name' => $googleUser->getName() ?: $user->name,
            'google_id' => $googleUser->getId(),
            'avatar' => $googleUser->getAvatar(),
            'email_verified_at' => $user->email_verified_at ?? now(),
            'last_login_at' => now(),
        ])->save();

        Auth::login($user, (bool) $request->session()->pull('login.remember', false));
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    private function deny(string $message): RedirectResponse
    {
        return redirect()->route('login')->withErrors(['google' => $message]);
    }
}
