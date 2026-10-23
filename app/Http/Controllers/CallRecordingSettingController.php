<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\GoogleDrive;
use App\Services\LeadGenerator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

/**
 * Settings → Call Recordings: the Google Drive the recordings go to, and each CRA phone's upload token for MacroDroid.
 */
class CallRecordingSettingController extends Controller
{
    public function index(): View
    {
        return view('settings.call-recordings', [
            'driveConnected' => GoogleDrive::isConnected(),
            'driveEmail' => GoogleDrive::connectedEmail(),
            'folderUrl' => GoogleDrive::folderUrl(),
            'cras' => LeadGenerator::cras()->loadMax('callRecordings', 'created_at'),
            'uploadUrl' => route('api.call-recordings.store'),
        ]);
    }

    /**
     * Google sign-in for the account whose Drive keeps the recordings; offline access gives a refresh token.
     */
    public function connect(): RedirectResponse
    {
        return Socialite::driver('google')
            ->redirectUrl(route('settings.call-recordings.drive-callback'))
            ->scopes([GoogleDrive::SCOPE])
            ->with(['access_type' => 'offline', 'prompt' => 'consent select_account'])
            ->redirect();
    }

    public function callback(Request $request, GoogleDrive $drive): RedirectResponse
    {
        $back = redirect()->route('settings.call-recordings.index');

        if ($request->has('error')) {
            return $back->withErrors(['drive' => 'Google Drive was not connected (sign-in cancelled).']);
        }

        try {
            $googleUser = Socialite::driver('google')->redirectUrl(route('settings.call-recordings.drive-callback'))->user();
        } catch (Throwable $e) {
            Log::warning('Google Drive connect failed', ['message' => $e->getMessage()]);

            return $back->withErrors(['drive' => 'Google sign-in failed. Please try again.']);
        }

        if (! $googleUser->refreshToken || ! in_array(GoogleDrive::SCOPE, (array) $googleUser->approvedScopes, true)) {
            return $back->withErrors(['drive' => 'Tick "See, edit, create and delete only the specific Google Drive files you use with this app" on the Google screen, then connect again.']);
        }

        try {
            $drive->connect($googleUser->refreshToken, strtolower((string) $googleUser->getEmail()), $request->user());
        } catch (Throwable $e) {
            report($e);

            return $back->withErrors(['drive' => 'Signed in, but the recordings folder could not be made: '.$e->getMessage()]);
        }

        return $back->with('status', 'Google Drive connected. Recordings go to the "'.config('services.google_drive.folder_name').'" folder.');
    }

    /**
     * A new upload token for the CRA's phone; the old one stops working. Shown once.
     */
    public function issueToken(User $user): RedirectResponse
    {
        abort_unless(LeadGenerator::cras()->contains('id', $user->id), 404);

        return redirect()->route('settings.call-recordings.index')
            ->with('new_token', ['cra' => $user->displayName(), 'token' => $user->issueRecordingToken()]);
    }
}
