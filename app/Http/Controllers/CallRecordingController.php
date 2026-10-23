<?php

namespace App\Http\Controllers;

use App\Models\CallRecording;
use App\Services\GoogleDrive;
use App\Services\LeadGenerator;
use App\Support\WorkingDate;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Calls uploaded from the CRAs' phones for a From–To range: each CRA's calls and AHT (average call length), and every
 * call with its customer and a player. A CRA sees and hears their own.
 */
class CallRecordingController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();
        $today = WorkingDate::realToday();
        $filters = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:'.$today->toDateString()],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'cra' => ['nullable', 'integer'],
            'match' => ['nullable', 'in:matched,unmatched'],
        ]);

        $from = CarbonImmutable::parse($filters['from'] ?? $today->toDateString());
        $to = CarbonImmutable::parse($filters['to'] ?? $from->max($today)->toDateString());
        $tz = config('segmentation.timezone');

        $cras = LeadGenerator::cras();
        $craId = match (true) {
            ! $user->can('call_recordings.view_all') => $user->id,
            filled($filters['cra'] ?? null) => (int) $filters['cra'],
            default => null,
        };

        // Calls on Manila days From–To.
        $inRange = CallRecording::query()
            ->whereBetween('called_at', [
                CarbonImmutable::parse($from->toDateString(), $tz)->utc(),
                CarbonImmutable::parse($to->toDateString(), $tz)->endOfDay()->utc(),
            ])
            ->when($craId, fn ($query) => $query->where('user_id', $craId));

        // AHT counts the calls whose length is known.
        $perCra = (clone $inRange)
            ->select('user_id', DB::raw('count(*) as calls'), DB::raw('avg(duration_seconds) as aht'), DB::raw('sum(duration_seconds) as talk'),
                DB::raw("sum(case when direction = 'in' then 1 else 0 end) as incoming"), DB::raw("sum(case when direction = 'out' then 1 else 0 end) as outgoing"),
                DB::raw('sum(case when lead_id is null then 1 else 0 end) as unmatched'))
            ->groupBy('user_id')->get()->keyBy('user_id');

        $team = (clone $inRange)
            ->selectRaw('count(*) as calls, avg(duration_seconds) as aht, sum(duration_seconds) as talk, sum(case when lead_id is null then 1 else 0 end) as unmatched')
            ->first();

        $calls = (clone $inRange)
            ->when(($filters['match'] ?? null) === 'matched', fn ($query) => $query->whereNotNull('lead_id'))
            ->when(($filters['match'] ?? null) === 'unmatched', fn ($query) => $query->whereNull('lead_id'))
            ->with(['user', 'lead'])
            ->latest('called_at')->latest('id')
            ->paginate(50)->withQueryString();

        return view('call-recordings.index', [
            'from' => $from,
            'to' => $to,
            'filters' => $filters,
            'craId' => $craId,
            'cras' => $cras,
            // Every CRA, those without calls too, in the usual CRA order.
            'rows' => $cras->when($craId, fn ($all) => $all->where('id', $craId))->map(fn ($cra) => [
                'cra' => $cra,
                'calls' => (int) ($perCra[$cra->id]->calls ?? 0),
                'incoming' => (int) ($perCra[$cra->id]->incoming ?? 0),
                'outgoing' => (int) ($perCra[$cra->id]->outgoing ?? 0),
                'unmatched' => (int) ($perCra[$cra->id]->unmatched ?? 0),
                'aht' => isset($perCra[$cra->id]->aht) ? (float) $perCra[$cra->id]->aht : null,
                'talk' => isset($perCra[$cra->id]->talk) ? (float) $perCra[$cra->id]->talk : null,
            ])->values(),
            'team' => [
                'calls' => (int) $team->calls,
                'aht' => $team->aht !== null ? (float) $team->aht : null,
                'talk' => $team->talk !== null ? (float) $team->talk : null,
                'unmatched' => (int) $team->unmatched,
            ],
            'calls' => $calls,
            'driveConnected' => GoogleDrive::isConnected(),
        ]);
    }

    /**
     * The recording's audio from Google Drive, passed through so the Drive file stays private. Forwards the player's
     * Range header so it can seek.
     */
    public function audio(Request $request, CallRecording $callRecording, GoogleDrive $drive): StreamedResponse
    {
        abort_unless($callRecording->user_id === $request->user()->id || $request->user()->can('call_recordings.view_all'), 403);

        try {
            $response = $drive->download($callRecording->drive_file_id, $request->header('Range'));
        } catch (Throwable $e) {
            report($e);
            abort(502, 'Google Drive did not send the recording.');
        }

        $body = $response->toPsrResponse()->getBody();
        $headers = array_filter([
            'Content-Type' => $callRecording->mime_type,
            'Content-Length' => $response->header('Content-Length'),
            'Content-Range' => $response->header('Content-Range'),
            'Accept-Ranges' => 'bytes',
            'Cache-Control' => 'private, max-age=3600',
        ]);

        return response()->stream(function () use ($body) {
            while (! $body->eof()) {
                echo $body->read(65536);
                flush();
            }
        }, $response->status(), $headers);
    }
}
