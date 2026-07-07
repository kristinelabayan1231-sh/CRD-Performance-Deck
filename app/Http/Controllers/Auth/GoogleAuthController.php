<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AllowedEmail;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;

class GoogleAuthController extends Controller
{
    public function redirect(): RedirectResponse
    {
        return Socialite::driver('google')->redirect();
    }

    public function callback(): RedirectResponse
    {
        try {
            $googleUser = Socialite::driver('google')->user();
        } catch (InvalidStateException) {
            return redirect()->route('login')->withErrors([
                'access' => 'Your sign-in session expired before Google redirected back. Please try again.',
            ]);
        }

        $allowed = AllowedEmail::whereRaw('lower(email) = ?', [strtolower($googleUser->getEmail())])->first();

        if (! $allowed) {
            return redirect()->route('login')->withErrors([
                'access' => "{$googleUser->getEmail()} does not have access to this app. Ask an admin to add it.",
            ]);
        }

        $user = User::updateOrCreate(
            ['email' => $allowed->email],
            [
                'name' => $googleUser->getName() ?: $googleUser->getNickname() ?: $allowed->email,
                'google_id' => $googleUser->getId(),
                'avatar' => $googleUser->getAvatar(),
                'is_admin' => $allowed->is_admin,
            ],
        );

        Auth::login($user, remember: true);

        return redirect()->intended(route('deck.index'));
    }
}
