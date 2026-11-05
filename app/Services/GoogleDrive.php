<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * The Google Drive that holds the call recordings, connected once in Settings → Call Recordings.
 *
 * Uses the drive.file scope: the app only sees the folder and files it created itself.
 */
class GoogleDrive
{
    public const SCOPE = 'https://www.googleapis.com/auth/drive.file';

    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const FILES_URL = 'https://www.googleapis.com/drive/v3/files';

    private const UPLOAD_URL = 'https://www.googleapis.com/upload/drive/v3/files';

    private const ACCESS_TOKEN_CACHE = 'google_drive.access_token';

    public static function isConnected(): bool
    {
        return Setting::value('google_drive.refresh_token') !== null;
    }

    public static function connectedEmail(): ?string
    {
        return Setting::value('google_drive.email');
    }

    public static function folderUrl(): ?string
    {
        $folderId = Setting::value('google_drive.folder_id');

        return $folderId ? "https://drive.google.com/drive/folders/{$folderId}" : null;
    }

    /**
     * Save the Google account that holds the recordings (from the Connect Google Drive sign-in).
     * A different account gets a new folder, since the app can't see the old account's files.
     */
    public function connect(string $refreshToken, string $email, User $by): void
    {
        if ($email !== self::connectedEmail()) {
            Setting::where('key', 'google_drive.folder_id')->delete();
        }

        Setting::put('google_drive.refresh_token', Crypt::encryptString($refreshToken), $by);
        Setting::put('google_drive.email', $email, $by);
        Cache::forget(self::ACCESS_TOKEN_CACHE);

        $this->folderId();
    }

    /**
     * Upload a file into the recordings folder (a resumable upload: the metadata first, then the bytes).
     *
     * @return string the Drive file id
     */
    public function upload(string $contents, string $name, string $mimeType): string
    {
        $session = $this->request()
            ->withHeaders(['X-Upload-Content-Type' => $mimeType, 'X-Upload-Content-Length' => strlen($contents)])
            ->post(self::UPLOAD_URL.'?uploadType=resumable', ['name' => $name, 'parents' => [$this->folderId()]]);

        $this->ensureOk($session, 'start the upload');

        $uploaded = $this->request()->timeout(300)->withBody($contents, $mimeType)->put($session->header('Location'));
        $this->ensureOk($uploaded, 'upload the file');

        return (string) $uploaded->json('id');
    }

    /**
     * The file's audio as a stream; $range (an HTTP Range header) lets the player seek without downloading it all.
     */
    public function download(string $fileId, ?string $range = null): Response
    {
        $response = $this->request()
            ->withOptions(['stream' => true])
            ->withHeaders(array_filter(['Range' => $range]))
            ->get(self::FILES_URL."/{$fileId}", ['alt' => 'media']);

        $this->ensureOk($response, 'read the recording');

        return $response;
    }

    /**
     * The recordings folder, created in the connected Drive the first time it is needed.
     */
    private function folderId(): string
    {
        $folderId = Setting::value('google_drive.folder_id');

        if ($folderId) {
            return $folderId;
        }

        $response = $this->request()->post(self::FILES_URL, [
            'name' => config('services.google_drive.folder_name'),
            'mimeType' => 'application/vnd.google-apps.folder',
        ]);
        $this->ensureOk($response, 'create the recordings folder');

        Setting::put('google_drive.folder_id', $response->json('id'));

        return $response->json('id');
    }

    private function request(): PendingRequest
    {
        return Http::withToken($this->accessToken())->connectTimeout(10)->timeout(60);
    }

    /**
     * A short-lived access token from the saved refresh token, kept until a minute before it expires.
     */
    private function accessToken(): string
    {
        if ($token = Cache::get(self::ACCESS_TOKEN_CACHE)) {
            return $token;
        }

        $refreshToken = Setting::value('google_drive.refresh_token');

        if ($refreshToken === null) {
            throw new RuntimeException('Google Drive is not connected. Connect it in Settings → Call Recordings.');
        }

        $response = Http::asForm()->connectTimeout(10)->timeout(30)->post(self::TOKEN_URL, [
            'client_id' => config('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'),
            'refresh_token' => Crypt::decryptString($refreshToken),
            'grant_type' => 'refresh_token',
        ]);
        $this->ensureOk($response, 'sign in (connect Google Drive again in Settings → Call Recordings)');

        $token = (string) $response->json('access_token');
        Cache::put(self::ACCESS_TOKEN_CACHE, $token, max(60, (int) $response->json('expires_in', 3600) - 60));

        return $token;
    }

    private function ensureOk(Response $response, string $step): void
    {
        if ($response->failed()) {
            throw new RuntimeException("Google Drive could not {$step}: HTTP {$response->status()}");
        }
    }
}
