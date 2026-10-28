<?php

namespace App\Http\Controllers;

use App\Models\CallRecording;
use App\Models\Lead;
use App\Models\User;
use App\Services\GoogleDrive;
use App\Support\AudioDuration;
use Carbon\CarbonImmutable;
use finfo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * POST /api/call-recordings from a CRA's phone (MacroDroid): the audio file is the request body, the call details are
 * query parameters, and the CRA's upload token (Settings → Call Recordings) is the Bearer token. A phone two CRAs
 * share saves each call under the CRA whose hours it falls in.
 */
class CallRecordingUploadController extends Controller
{
    /** Largest recording accepted (about 2 hours of call audio). */
    public const MAX_BYTES = 50 * 1024 * 1024;

    public function __invoke(Request $request, GoogleDrive $drive): JsonResponse
    {
        $phoneOwner = User::findByRecordingToken($request->bearerToken());

        if (! $phoneOwner) {
            return response()->json(['message' => 'Unknown or missing upload token.'], 401);
        }

        $details = $request->validate([
            'number' => ['required', 'string', 'max:40'],
            'direction' => ['nullable', Rule::in(array_keys(CallRecording::DIRECTIONS))],
            'duration' => ['nullable', 'integer', 'min:0', 'max:86400'],
            'called_at' => ['nullable', 'date'],
            'filename' => ['nullable', 'string', 'max:255'],
            // The lead the tracker's Call button dialled, sent back by the phone.
            'lead' => ['nullable', 'integer'],
        ]);

        $filename = isset($details['filename']) ? basename($details['filename']) : null;

        // The catch-up upload sends files again; the phone may move them once it gets a 200. A shared phone's file
        // may already be under the CRA sharing it.
        $phoneCraIds = array_filter([$phoneOwner->id, $phoneOwner->shared_phone_cra_id]);

        if ($filename !== null && ($existing = CallRecording::whereIn('user_id', $phoneCraIds)->where('original_filename', $filename)->first())) {
            return response()->json(['message' => 'Already uploaded.', 'id' => $existing->id]);
        }

        $contents = $request->getContent();
        $mimeType = (new finfo(FILEINFO_MIME_TYPE))->buffer($contents) ?: '';

        if ($contents === '' || strlen($contents) > self::MAX_BYTES || ! $this->isAudio($mimeType)) {
            return response()->json(['message' => 'The body must be the recording itself (an audio file up to 50 MB).'], 422);
        }

        $calledAt = isset($details['called_at']) ? CarbonImmutable::parse($details['called_at'], config('segmentation.timezone')) : CarbonImmutable::now();
        $cra = $phoneOwner->phoneCraAt($calledAt);
        $number = $this->callNumber($details['number'], $filename);
        $phoneKey = CallRecording::phoneKey($number);

        try {
            $fileId = $drive->upload($contents, $this->driveName($cra, $calledAt, $phoneKey, $filename), $mimeType);
        } catch (Throwable $e) {
            report($e);

            // Not 2xx, so the phone keeps the file for its next try.
            return response()->json(['message' => 'Google Drive did not take the file; try again later.'], 503);
        }

        $recording = CallRecording::create([
            'user_id' => $cra->id,
            'lead_id' => $this->lead($details['lead'] ?? null, $phoneKey, $cra)?->id,
            'phone_number' => $number,
            'phone_key' => $phoneKey,
            'direction' => $details['direction'] ?? null,
            // Saved in UTC: a Manila time would be stored as is.
            'called_at' => $calledAt->utc(),
            // The phone's call length when sent, else the recording's own length.
            'duration_seconds' => ($details['duration'] ?? 0) > 0 ? $details['duration'] : AudioDuration::seconds($contents),
            'drive_file_id' => $fileId,
            'mime_type' => $mimeType,
            'size_bytes' => strlen($contents),
            'original_filename' => $filename,
        ]);

        return response()->json(['message' => 'Saved.', 'id' => $recording->id, 'matched' => $recording->lead_id !== null], 201);
    }

    /**
     * The lead the Call button dialled when the phone sends it back and its number is this call's (a leftover from an
     * earlier call isn't trusted), else the lead matched by number.
     */
    private function lead(?int $leadId, string $phoneKey, User $cra): ?Lead
    {
        $dialled = $leadId ? Lead::find($leadId) : null;

        return $dialled && CallRecording::phoneKey($dialled->phone_number) === $phoneKey
            ? $dialled
            : CallRecording::matchLead($phoneKey, $cra);
    }

    /**
     * The number sent, or the one in the recording's file name (e.g. "09930353215 2026-10-28 19-44-44.m4a") when the
     * phone sent something else, such as a MacroDroid variable it didn't fill in ("{call_number}").
     */
    private function callNumber(string $number, ?string $filename): string
    {
        if (strlen(CallRecording::phoneKey($number)) >= 10 || $filename === null || ! preg_match('/\+?\d{10,13}/', $filename, $match)) {
            return $number;
        }

        return $match[0];
    }

    /**
     * finfo names M4A and 3GP calls video/*.
     */
    private function isAudio(string $mimeType): bool
    {
        return str_starts_with($mimeType, 'audio/') || in_array($mimeType, ['video/mp4', 'video/3gpp', 'application/ogg'], true);
    }

    /**
     * e.g. "2026-10-23 1405 regina 09171234567.m4a", so the Drive folder reads by date.
     */
    private function driveName(User $cra, CarbonImmutable $calledAt, string $phoneKey, ?string $filename): string
    {
        $extension = $filename ? pathinfo($filename, PATHINFO_EXTENSION) : '';

        return $calledAt->timezone(config('segmentation.timezone'))->format('Y-m-d Hi')
            .' '.Str::slug($cra->displayName()).' '.CallRecording::dialNumber($phoneKey).($extension !== '' ? '.'.$extension : '');
    }
}
